/**
 * Animated 3D background (floating gradient shapes + slow rotation/parallax).
 * Requires the global THREE (loaded via CDN before this script).
 * Degrades gracefully: if THREE/WebGL isn't available, the page just keeps
 * the CSS gradient background from site-theme.css — nothing breaks.
 */
(function () {
  function init() {
    if (typeof THREE === 'undefined') return;

    var canvas = document.createElement('canvas');
    canvas.id = 'bg3d-canvas';
    document.body.insertBefore(canvas, document.body.firstChild);

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(55, window.innerWidth / window.innerHeight, 0.1, 100);
    camera.position.z = 14;

    var renderer;
    try {
      renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
    } catch (e) {
      return; // WebGL not available — silently keep CSS-only background
    }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(window.innerWidth, window.innerHeight);

    var colors = [0x7c3aed, 0xec4899, 0x3b82f6, 0x22d3ee, 0xf472b6];
    var shapes = [];
    var geometries = [
      new THREE.IcosahedronGeometry(0.9, 0),
      new THREE.TorusGeometry(0.7, 0.22, 16, 60),
      new THREE.OctahedronGeometry(0.85, 0),
      new THREE.TetrahedronGeometry(1.0, 0),
      new THREE.IcosahedronGeometry(0.6, 1),
    ];

    var SHAPE_COUNT = 6;
    for (var i = 0; i < SHAPE_COUNT; i++) {
      var geo = geometries[i % geometries.length];
      var mat = new THREE.MeshStandardMaterial({
        color: colors[i % colors.length],
        roughness: 0.4,
        metalness: 0.15,
        transparent: true,
        opacity: 0.4,
        emissive: colors[i % colors.length],
        emissiveIntensity: 0.08,
      });
      var mesh = new THREE.Mesh(geo, mat);
      // Bias shapes toward the edges/corners and further back, so they read
      // as ambient background motion instead of competing with page content.
      var side = i % 2 === 0 ? -1 : 1;
      mesh.position.set(
        side * (8 + Math.random() * 8),
        (Math.random() - 0.5) * 12,
        (Math.random() - 0.5) * 6 - 8
      );
      mesh.rotation.set(Math.random() * Math.PI, Math.random() * Math.PI, 0);
      mesh.userData.spin = {
        x: (Math.random() - 0.5) * 0.003,
        y: (Math.random() - 0.5) * 0.004,
      };
      mesh.userData.floatOffset = Math.random() * Math.PI * 2;
      mesh.userData.baseY = mesh.position.y;
      scene.add(mesh);
      shapes.push(mesh);
    }

    var ambient = new THREE.AmbientLight(0xffffff, 0.65);
    scene.add(ambient);
    var dirLight = new THREE.DirectionalLight(0xffffff, 0.8);
    dirLight.position.set(5, 8, 10);
    scene.add(dirLight);
    var pointLight = new THREE.PointLight(0xec4899, 0.6, 30);
    pointLight.position.set(-6, 4, 6);
    scene.add(pointLight);

    var mouseX = 0, mouseY = 0;
    window.addEventListener('mousemove', function (e) {
      mouseX = (e.clientX / window.innerWidth - 0.5) * 2;
      mouseY = (e.clientY / window.innerHeight - 0.5) * 2;
    });

    window.addEventListener('resize', function () {
      camera.aspect = window.innerWidth / window.innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(window.innerWidth, window.innerHeight);
    });

    var clock = new THREE.Clock();
    function animate() {
      requestAnimationFrame(animate);
      var t = clock.getElapsedTime();

      shapes.forEach(function (mesh) {
        mesh.rotation.x += mesh.userData.spin.x;
        mesh.rotation.y += mesh.userData.spin.y;
        mesh.position.y = mesh.userData.baseY + Math.sin(t * 0.4 + mesh.userData.floatOffset) * 0.6;
      });

      // subtle parallax toward mouse position
      camera.position.x += (mouseX * 0.6 - camera.position.x) * 0.02;
      camera.position.y += (-mouseY * 0.4 - camera.position.y) * 0.02;
      camera.lookAt(0, 0, 0);

      renderer.render(scene, camera);
    }
    animate();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
