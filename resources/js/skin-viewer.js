// Lazy-loaded 3D weapon model viewer. Kept as its own module (dynamically
// imported from skins/index.blade.php, never from app.js) so Three.js and
// its loaders - a few hundred KB - are only fetched by someone who actually
// opens a 3D preview, not on every page load.
//
// Models: real GLB exports of CS2's own weapon geometry, sourced from
// LielXD/CS2-WeaponPaints-Website (MIT licensed, same public-CDN pattern
// already used for the flat 2D preview images - see skinImageUrl() in
// skins/index.blade.php). Filenames match our own weapon classnames
// 1:1 (weapon_ak47.glb, weapon_knife_karambit.glb, ...), confirmed against
// a handful of real names before wiring this up.
//
// Scope: this renders the model's own embedded materials as exported -
// it does NOT project our flat skin preview image onto the mesh. That
// image is a fixed-angle beauty render with its own baked lighting; mapping
// it onto a different model's real UV unwrap would come out stretched and
// wrong more often than not, which is worse than not attempting it. The 2D
// preview stays the accurate reference for what the skin actually looks
// like; this view is for seeing the weapon's real 3D shape and rotating it.

import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const MODEL_BASE = 'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/main/src/%5Bmodels%5D/';

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
 * Mounts a rotatable 3D view of one GLB model into `container`.
 *
 * @param {HTMLElement} container
 * @param {string} url
 * @returns {Promise<() => void>} a dispose function - call it before
 *   mounting a different model or removing the container, or the
 *   render loop and its WebGL context leak.
 */
export async function mount(container, url) {
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

    const loader = new GLTFLoader();
    const gltf = await loader.loadAsync(url);

    if (disposed) {
        return () => {};
    }

    loadedRoot = gltf.scene;
    scene.add(loadedRoot);

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

    return () => {
        disposed = true;
        if (frameId !== null) cancelAnimationFrame(frameId);
        resizeObserver.disconnect();
        controls.dispose();
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
    };
}
