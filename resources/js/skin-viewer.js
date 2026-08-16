// Lazy-loaded 3D weapon model viewer. Kept as its own module (dynamically
// imported from skins/index.blade.php, never from app.js) so Three.js and
// its loaders - a few hundred KB - are only fetched by someone who actually
// opens a 3D preview, not on every page load.
//
// Models AND textures: real GLB exports of CS2's own weapon geometry, plus
// the matching per-paint diffuse/metalness textures, both sourced from
// LielXD/CS2-WeaponPaints-Website (MIT licensed, same public-CDN pattern
// already used for the flat 2D preview images - see skinImageUrl() in
// skins/index.blade.php). Filenames match our own weapon classnames 1:1
// (weapon_ak47.glb, weapon_ak47/1449.png, ...), and the paint ids are the
// same Valve paintkit numbering our own catalog already uses - that
// project's PHP backend reads the paint id straight out of a
// wp_player_skins.weapon_paint_id column, the same CS2_Skin schema we read
// (verified: paint ids 801/1171/1207, already confirmed real AK-47 ids
// against our own catalog earlier, all resolve to real texture files
// there). Applying THIS project's own texture to THIS project's own model
// is the correct pairing - both were authored together, unlike the flat
// Nereziel preview image, which is a fixed-angle beauty render with its
// own baked lighting and was never meant to be projected onto a different
// model's UV unwrap.
//
// The base-texture application itself (plain `material.map` assignment
// after traversing the scene, skipping the arm/scope meshes) mirrors that
// project's own weaponviewer.js LoadTexture() - confirmed by reading its
// source rather than guessing, since a wrong technique here (e.g. their
// separate ProjectedMaterial-based sticker system, which is NOT what the
// base skin uses) would look worse than no texture at all.

import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const MODEL_BASE = 'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/main/src/%5Bmodels%5D/';
const TEXTURE_BASE = 'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/main/src/%5Btextures%5D/';

export function modelUrl(name) {
    return `${MODEL_BASE}${encodeURIComponent(name)}.glb`;
}

// The source repo stores each file as whichever of .png/.webp it happened
// to be captured as - no single extension covers every weapon+paint pair.
async function loadTextureTryingExtensions(loader, weaponName, paintId, suffix) {
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

export function webglSupported() {
    try {
        const canvas = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (canvas.getContext('webgl2') || canvas.getContext('webgl')));
    } catch (e) {
        return false;
    }
}

/**
 * Mounts a rotatable 3D view of one weapon's model into `container`, with
 * whichever paint is currently equipped applied as the surface texture.
 *
 * @param {HTMLElement} container
 * @param {string} weaponName - e.g. "weapon_ak47", matches both the .glb
 *   filename and the texture folder name.
 * @param {number} paintId - 0 for the factory-default look (model renders
 *   with its own embedded materials, no texture swap).
 * @returns {Promise<{dispose: () => void, setPaint: (id: number) => Promise<void>}>}
 *   dispose() must be called before mounting a different weapon or removing
 *   the container, or the render loop and its WebGL context leak. setPaint()
 *   swaps just the texture on the already-loaded model - call it when the
 *   user picks a different paint while the 3D tab is already open, instead
 *   of re-mounting (re-fetching a multi-MB .glb) for every click.
 */
export async function mount(container, weaponName, paintId) {
    const width = container.clientWidth || 320;
    const height = container.clientHeight || 240;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(40, width / height, 0.01, 100);

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(width, height);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    container.innerHTML = '';
    container.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 1.1));
    const key = new THREE.DirectionalLight(0xffffff, 2.2);
    key.position.set(2, 3, 4);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.8);
    fill.position.set(-3, -1, -2);
    scene.add(fill);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.enablePan = false;
    controls.minDistance = 0.05;
    controls.maxDistance = 5;

    let frameId = null;
    let disposed = false;
    let loadedRoot = null;
    let currentTextures = [];

    const gltfLoader = new GLTFLoader();
    const textureLoader = new THREE.TextureLoader();
    const gltf = await gltfLoader.loadAsync(modelUrl(weaponName));

    if (disposed) {
        return { dispose: () => {}, setPaint: async () => {} };
    }

    loadedRoot = gltf.scene;
    scene.add(loadedRoot);

    // Applies (or clears, for paintId 0) the diffuse/metalness texture for
    // one paint onto every mesh material except the hand/arm and scope
    // glass - mirrors LielXD's own weaponviewer.js LoadTexture(), which
    // excludes those same two by material name so a skin never paints the
    // player's arm model or a scope's lens.
    const applyPaint = async (id) => {
        currentTextures.forEach((t) => t.dispose());
        currentTextures = [];

        const [map, metalnessMap] = id
            ? await Promise.all([
                loadTextureTryingExtensions(textureLoader, weaponName, id, ''),
                loadTextureTryingExtensions(textureLoader, weaponName, id, '_metal'),
            ])
            : [null, null];

        [map, metalnessMap].forEach((t) => {
            if (!t) return;
            t.colorSpace = THREE.SRGBColorSpace;
            t.wrapS = THREE.RepeatWrapping;
            t.wrapT = THREE.RepeatWrapping;
            t.flipY = false;
            currentTextures.push(t);
        });

        loadedRoot.traverse((child) => {
            if (!child.isMesh || !child.material) return;
            const name = (child.material.name || '').toLowerCase();
            if (name.includes('bare_arm') || name.includes('scope')) return;

            child.material.map = map;
            child.material.metalnessMap = metalnessMap;
            child.material.needsUpdate = true;
        });
    };

    await applyPaint(paintId);

    // Auto-frame: centre the model and pick a camera distance from its own
    // size, since every weapon model here ships at a different real-world
    // scale (a knife and a shotgun are not the same number of units).
    const box = new THREE.Box3().setFromObject(loadedRoot);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    loadedRoot.position.sub(center);

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
        setPaint: async (id) => {
            if (disposed) return;
            await applyPaint(id);
        },
    };
}
