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
 * The source repo stores each file as whichever of .png/.webp it happened
 * to be captured as - no single extension covers every weapon+paint pair,
 * so both are tried. A miss is not an error: plenty of paints simply have
 * no texture published, and the model still renders with its own
 * materials.
 */
async function loadPaintTexture(loader, weaponName, paintId, suffix) {
    for (const ext of ['png', 'webp']) {
        const url = `${TEXTURE_BASE}${encodeURIComponent(weaponName)}/${paintId}${suffix}.${ext}`;
        try {
            return await loader.loadAsync(url);
        } catch (e) {
            // try the next extension
        }
    }
    return null;
}

async function loadPaintTextures(loader, weaponName, paintId) {
    const [map, metalnessMap] = await Promise.all([
        loadPaintTexture(loader, weaponName, paintId, ''),
        loadPaintTexture(loader, weaponName, paintId, '_metal'),
    ]);

    [map, metalnessMap].forEach((t) => {
        if (!t) return;
        t.colorSpace = THREE.SRGBColorSpace;
        t.wrapS = THREE.RepeatWrapping;
        t.wrapT = THREE.RepeatWrapping;
        t.flipY = false;
    });

    return { map, metalnessMap };
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
    const pmremGenerator = new THREE.PMREMGenerator(renderer);
    const roomEnvironment = new RoomEnvironment();
    const environmentTexture = pmremGenerator.fromScene(roomEnvironment, 0.04).texture;
    scene.environment = environmentTexture;
    roomEnvironment.dispose?.();

    // Keeps highlights on the barrel/slide readable; the environment alone
    // is diffuse and leaves edges flat.
    const key = new THREE.DirectionalLight(0xffffff, 1.4);
    key.position.set(2, 3, 4);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.5);
    fill.position.set(-3, -1, -2);
    scene.add(fill);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.enablePan = false;

    let frameId = null;
    let disposed = false;
    let currentTextures = [];

    const gltfLoader = new GLTFLoader();
    const textureLoader = new THREE.TextureLoader();

    // Model and paint textures are fetched together rather than one after
    // the other - they are independent files on the same host, and the
    // texture is useless without the model anyway, so serialising them
    // only added their two round-trips together.
    const [gltf, initialTextures] = await Promise.all([
        gltfLoader.loadAsync(modelUrl(weaponName), (event) => {
            if (onProgress && event.total) onProgress(event.loaded / event.total);
        }),
        paintId ? loadPaintTextures(textureLoader, weaponName, paintId) : Promise.resolve(null),
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
    const originalMaps = new Map();
    root.traverse((child) => {
        if (child.isMesh && child.material) {
            originalMaps.set(child.material, {
                map: child.material.map ?? null,
                metalnessMap: child.material.metalnessMap ?? null,
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

            const original = originalMaps.get(child.material) ?? { map: null, metalnessMap: null };
            child.material.map = textures?.map ?? original.map;
            child.material.metalnessMap = textures?.metalnessMap ?? original.metalnessMap;
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

        const textures = id ? await loadPaintTextures(textureLoader, weaponName, id) : null;
        if (disposed) return;

        currentTextures.forEach((t) => t.dispose());
        currentTextures = textures ? [textures.map, textures.metalnessMap].filter(Boolean) : [];

        applyTextures(textures);
    };

    setVariant(options.legacy);
    currentTextures = initialTextures ? [initialTextures.map, initialTextures.metalnessMap].filter(Boolean) : [];
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
