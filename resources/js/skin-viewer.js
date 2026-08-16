// Lazy-loaded 3D weapon model viewer. Kept as its own module (dynamically
// imported from skins/index.blade.php, never from app.js) so Three.js and
// its loaders - a few hundred KB - are only fetched by someone who actually
// opens a 3D preview, not on every page load.
//
// Models AND textures come from LielXD/CS2-WeaponPaints-Website (MIT), the
// same public-CDN pattern already used for the flat 2D preview images.
// Filenames match our own weapon classnames 1:1 and the paint ids are the
// same Valve paintkit numbering our own catalog uses, so no mapping layer
// is needed.
//
// ---------------------------------------------------------------------
// THE LEGACY/HD MESH SPLIT - the thing that made earlier builds render
// wrong. Every gun .glb ships TWO complete, fully overlapping weapon
// meshes as sibling root nodes:
//
//     children[0]  "...vmdl_c.body_legacy"   (CS:GO-era geometry + UVs)
//     children[1]  "...vmdl_c.body_hd"       (CS2 geometry + UVs)
//
// Only ONE is ever correct for a given paint, because Valve authored each
// finish against one specific model - which is exactly what the item
// schema's `UseLegacyModel` flag records (our own weapon_to_paintkits.json
// already carries it; cross-checked against LielXD's independently
// maintained list at 1800/1801 agreement). Rendering both at once is what
// produced the half-textured, z-fighting mess: two sets of geometry in the
// same space, only one of them wearing the skin texture.
//
// Both meshes are kept loaded and toggled with .visible instead of being
// removed, so switching between a legacy-model paint and an HD-model paint
// is instant rather than re-downloading a multi-MB .glb.
//
// Not every model has the split: taser, knives and gloves ship a single
// root node, so the toggle simply no-ops there.
// ---------------------------------------------------------------------
//
// Wear (float) and pattern seed are deliberately NOT simulated. Those
// require CS2's own Source 2 composite shader plus the raw pattern/wear
// -mask textures from the game files; what this CDN serves is one
// pre-baked composite PNG per weapon+paint, already flattened at a fixed
// wear and seed. No amount of shader work recovers the inputs from a
// baked output. The upstream project this borrows from says the same
// thing in its own UI ("Wear and seed are saved to the server, but are
// not shown in the beta 3D preview yet"). Faking it would show players a
// gun that does not match what they get in-game, which is worse than
// showing the honest fixed-wear render.

import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

const MODEL_BASE = 'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/main/src/%5Bmodels%5D/';
const TEXTURE_BASE = 'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/main/src/%5Btextures%5D/';

export function modelUrl(name) {
    return `${MODEL_BASE}${encodeURIComponent(name)}.glb`;
}

export function webglSupported() {
    try {
        const canvas = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (canvas.getContext('webgl2') || canvas.getContext('webgl')));
    } catch (e) {
        return false;
    }
}

/**
 * The CDN ships these as full capture resolution - up to 4096x4096, several
 * megabytes each - because that is what a wear/pattern-accurate export
 * happened to produce, not because a weapon preview panel needs it.
 * Decoding and GPU-uploading that raw size is what made switching paints
 * take several real seconds; createImageBitmap's own resize (done during
 * decode, not as a second pass after) caps both without a visible quality
 * loss at the size this viewer actually renders at.
 */
const MAX_TEXTURE_SIZE = 1024;

async function fetchDownscaledBitmap(url) {
    const response = await fetch(url);
    if (!response.ok) throw new Error(String(response.status));
    const blob = await response.blob();
    const full = await createImageBitmap(blob);

    if (full.width <= MAX_TEXTURE_SIZE && full.height <= MAX_TEXTURE_SIZE) {
        return full;
    }

    const scale = MAX_TEXTURE_SIZE / Math.max(full.width, full.height);
    const resized = await createImageBitmap(full, {
        resizeWidth: Math.round(full.width * scale),
        resizeHeight: Math.round(full.height * scale),
        resizeQuality: 'high',
    });
    full.close();

    return resized;
}

/**
 * The source repo stores each file as whichever of .png/.webp it happened
 * to be captured as - no single extension covers every weapon+paint pair,
 * so both are tried. A miss is not an error: plenty of paints simply have
 * no texture published, and the model still renders with its own
 * materials.
 *
 * `isColor` distinguishes the base-color capture (needs sRGB decoding,
 * like any photo) from the "_metal" file, which is data - a combined
 * AO/roughness/metalness map, per the same channel packing GLTFLoader
 * already used for the model's own materials - and must stay linear.
 * Tagging it sRGB was silently skewing every metalness/roughness/AO value
 * read from it through gamma decoding.
 */
async function loadPaintTexture(weaponName, paintId, suffix, isColor) {
    for (const ext of ['png', 'webp']) {
        const url = `${TEXTURE_BASE}${encodeURIComponent(weaponName)}/${paintId}${suffix}.${ext}`;
        try {
            const bitmap = await fetchDownscaledBitmap(url);
            const texture = new THREE.Texture(bitmap);
            texture.colorSpace = isColor ? THREE.SRGBColorSpace : THREE.NoColorSpace;
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.RepeatWrapping;
            texture.flipY = false;
            texture.needsUpdate = true;
            return texture;
        } catch (e) {
            // try the next extension
        }
    }
    return null;
}

async function loadPaintTextures(weaponName, paintId) {
    const [map, armMap] = await Promise.all([
        loadPaintTexture(weaponName, paintId, '', true),
        loadPaintTexture(weaponName, paintId, '_metal', false),
    ]);

    return { map, armMap };
}

/**
 * Mounts a rotatable 3D view of one weapon into `container`.
 *
 * @param {HTMLElement} container
 * @param {string} weaponName  e.g. "weapon_ak47" - matches both the .glb
 *   filename and the texture folder name.
 * @param {number} paintId  0 for the factory-default finish, which renders
 *   the model's own embedded textures untouched (the GLB ships real
 *   baseColor/normal/AO/metalness maps for the stock weapon - an earlier
 *   build nulled `material.map` for paint 0 and got a blank white gun).
 * @param {{legacy?: boolean, onProgress?: (ratio: number) => void}} [options]
 *   `legacy` picks which of the two bundled meshes this paint belongs on
 *   (see the header note); it comes from the item schema's UseLegacyModel.
 * @returns {Promise<{dispose: () => void, setPaint: (id: number, legacy?: boolean) => Promise<void>}>}
 */
export async function mount(container, weaponName, paintId, options = {}) {
    const { onProgress } = options;

    const width = container.clientWidth || 320;
    const height = container.clientHeight || 240;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(40, width / height, 0.01, 100);

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(width, height);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.1;
    container.innerHTML = '';
    container.appendChild(renderer.domElement);

    // Procedural studio environment rather than a downloaded .hdr. An
    // earlier build pulled a 1.5MB environment.hdr from the CDN purely for
    // reflections; RoomEnvironment builds an equivalent lighting rig on the
    // GPU from nothing, which is 1.5MB less to wait through on a feature
    // whose whole complaint was that it took too long to open.
    //
    // Every gun material here is metalness:1/roughness:1 with no separate
    // scalar override, so its appearance comes entirely from its ORM map
    // and the light hitting it - a sharp, low-spread environment (blur
    // 0.04) plus two lights left large areas of the model reading as flat
    // black with nothing to reflect, which is what made "default has no
    // texture" and "lighting looks wrong" the same complaint in practice.
    const pmremGenerator = new THREE.PMREMGenerator(renderer);
    const roomEnvironment = new RoomEnvironment();
    const environmentTexture = pmremGenerator.fromScene(roomEnvironment, 0.2).texture;
    scene.environment = environmentTexture;
    roomEnvironment.dispose?.();

    const key = new THREE.DirectionalLight(0xffffff, 2.0);
    key.position.set(2, 3, 4);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 1.0);
    fill.position.set(-3, -1, -2);
    scene.add(fill);
    const rim = new THREE.DirectionalLight(0xffffff, 0.8);
    rim.position.set(0, -2, 3);
    scene.add(rim);
    const ambient = new THREE.AmbientLight(0xffffff, 0.5);
    scene.add(ambient);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.enablePan = false;

    let frameId = null;
    let disposed = false;
    let currentTextures = [];

    const gltfLoader = new GLTFLoader();

    // Model and paint textures are fetched together rather than one after
    // the other - they are independent files on the same host, and the
    // texture is useless without the model anyway, so serialising them
    // only added their two round-trips together.
    const [gltf, initialTextures] = await Promise.all([
        gltfLoader.loadAsync(modelUrl(weaponName), (event) => {
            if (onProgress && event.total) onProgress(event.loaded / event.total);
        }),
        paintId ? loadPaintTextures(weaponName, paintId) : Promise.resolve(null),
    ]);

    if (disposed) {
        return { dispose: () => {}, setPaint: async () => {} };
    }

    const root = gltf.scene;
    scene.add(root);

    // Identify the two variants by node name rather than by child index.
    // Index order happens to be [legacy, hd] in every file checked, but a
    // name match cannot silently pick the wrong mesh if that order ever
    // changes, and it degrades to "no split" cleanly for the single-mesh
    // models (taser/knives/gloves) instead of removing their only mesh.
    const findVariant = (needle) => root.children.find((child) => (child.name || '').includes(needle)) ?? null;
    const legacyMesh = findVariant('body_legacy');
    const hdMesh = findVariant('body_hd');
    const hasVariants = !!(legacyMesh && hdMesh);

    // The stock weapon's own maps, kept so paint 0 can restore exactly what
    // the file shipped with instead of clearing to an untextured surface.
    // GLTFLoader points metalnessMap/roughnessMap/aoMap at the *same*
    // combined texture (glTF always packs occlusion/roughness/metalness
    // into one image) - captured once here as armMap.
    const originalMaps = new Map();
    root.traverse((child) => {
        if (child.isMesh && child.material) {
            originalMaps.set(child.material, {
                map: child.material.map ?? null,
                armMap: child.material.metalnessMap ?? null,
            });
        }
    });

    const isPaintable = (material) => {
        const name = (material.name || '').toLowerCase();

        // A skin never covers the player's arm model or a scope lens -
        // matches how the upstream viewer filters these same two.
        return !name.includes('bare_arm') && !name.includes('scope');
    };

    const applyTextures = (textures) => {
        const target = hasVariants ? (legacyMesh.visible ? legacyMesh : hdMesh) : root;

        target.traverse((child) => {
            if (!child.isMesh || !child.material || !isPaintable(child.material)) return;

            const original = originalMaps.get(child.material) ?? { map: null, armMap: null };
            const armMap = textures?.armMap ?? original.armMap;

            child.material.map = textures?.map ?? original.map;
            // A paint's "_metal" file is the stock combined map's
            // replacement, not an add-on - it has to go on all three
            // properties together. Swapping only metalnessMap while
            // roughnessMap/aoMap kept pointing at the *stock* weapon's map
            // is what produced the patchy, partly-black look on painted
            // guns: the new paint's colour sat under the old weapon's
            // roughness/occlusion pattern instead of its own.
            child.material.metalnessMap = armMap;
            child.material.roughnessMap = armMap;
            child.material.aoMap = armMap;
            child.material.needsUpdate = true;
        });
    };

    const setVariant = (useLegacy) => {
        if (!hasVariants) return;
        legacyMesh.visible = !!useLegacy;
        hdMesh.visible = !useLegacy;
    };

    const applyPaint = async (id, useLegacy) => {
        setVariant(useLegacy);

        const textures = id ? await loadPaintTextures(weaponName, id) : null;
        if (disposed) return;

        currentTextures.forEach((t) => t.dispose());
        currentTextures = textures ? [textures.map, textures.armMap].filter(Boolean) : [];

        applyTextures(textures);
    };

    setVariant(options.legacy);
    currentTextures = initialTextures ? [initialTextures.map, initialTextures.armMap].filter(Boolean) : [];
    applyTextures(initialTextures);

    // Auto-frame from whichever mesh is actually visible - every weapon
    // model here ships at a different real-world scale (a knife and a
    // shotgun are not the same number of units), and a bounding box taken
    // across both overlapping variants would frame slightly wide.
    const framingTarget = hasVariants ? (legacyMesh.visible ? legacyMesh : hdMesh) : root;
    const box = new THREE.Box3().setFromObject(framingTarget);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    root.position.sub(center);

    const radius = Math.max(size.length() * 0.5, 0.01);
    camera.position.set(radius * 1.6, radius * 0.9, radius * 1.6);
    camera.near = radius / 100;
    camera.far = radius * 100;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.minDistance = radius * 0.4;
    controls.maxDistance = radius * 4;
    controls.update();

    const animate = () => {
        if (disposed) return;
        frameId = requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
    };
    animate();

    const resize = () => {
        const w = container.clientWidth || width;
        const h = container.clientHeight || height;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    };
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container);

    return {
        dispose: () => {
            disposed = true;
            if (frameId !== null) cancelAnimationFrame(frameId);
            resizeObserver.disconnect();
            controls.dispose();
            currentTextures.forEach((t) => t.dispose());
            environmentTexture.dispose();
            pmremGenerator.dispose();
            scene.traverse((obj) => {
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material) {
                    const materials = Array.isArray(obj.material) ? obj.material : [obj.material];
                    materials.forEach((m) => {
                        Object.values(m).forEach((v) => { if (v && v.isTexture) v.dispose(); });
                        m.dispose();
                    });
                }
            });
            renderer.dispose();
            if (renderer.domElement.parentNode) {
                renderer.domElement.parentNode.removeChild(renderer.domElement);
            }
        },
        setPaint: async (id, legacy) => {
            if (disposed) return;
            await applyPaint(id, legacy);
        },
    };
}
