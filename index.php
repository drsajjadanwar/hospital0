<?php
session_start();
// For demonstration purposes, you might set $_SESSION['logged_in'] to true when a user logs in.
// For example, uncomment the line below to simulate a logged-in user:
// $_SESSION['logged_in'] = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>hospital0 Portal</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    /* --- NEW BEAUTIFICATION STYLES --- */
    
    /* Override the global gradient from style.css for this page */
    body {
        background-image: none !important;
        background-color: #121212 !important;
    }

    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    /* --- Animated Starfield Background --- */
    @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
    .stars, .twinkling {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      width: 100%; height: 100%; display: block; z-index: -3;
    }
    .stars { background: #000 url(/media/stars.png) repeat top center; }
    .twinkling {
      background: transparent url(/media/twinkling.png) repeat top center;
      animation: move-twink-back 200s linear infinite;
    }

    /* --- 3D Canvas Background --- */
    #dna-canvas {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        z-index: -2; opacity: 0.3;
    }

    /* --- Title Enhancement --- */
    .site-title {
        text-align: center;
        margin: 20px 0 40px 0;
        font-weight: 300 !important;
        font-size: 4rem;
        color: #fff;
        text-shadow: 0 0 10px rgba(0, 229, 255, 0.7), 0 0 20px rgba(0, 229, 255, 0.5);
    }
    
    /* --- Glassmorphism for Role Cards --- */
    .group-card {
      display: block;
      text-align: center;
      margin-bottom: 30px;
      color: #fff;
      padding: 20px;
      border-radius: 15px;
      text-decoration: none;
      
      /* The Glass Effect */
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
      
      transition: background 0.3s ease, transform 0.3s ease;
    }

    .group-card:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-5px); /* Consistent lift effect */
    }

    .group-card img {
      max-height: 80px;
      display: block;
      margin: 0 auto 15px;
    }

    .group-card h5 {
      margin: 5px 0;
      font-weight: 500;
      color: #ffffff;
    }
  </style>

</head>
<body>

<div class="stars"></div>
<div class="twinkling"></div>
<canvas id="dna-canvas"></canvas>

  <?php include_once __DIR__ . '/includes/header.php'; ?>

  <div class="container">
    <div style="clear: both;"></div>
  
    <h1 class="site-title">hospital0</h1>

    <div class="row">
      <?php
      // Array of groups and corresponding logo filenames
      $roles = [
        'Chief Medical Officer' => 'cmo.png',
        'Aesthetician' => 'aesthetician.png',
        'Dentist' => 'dentist.png',
        'Medical Officers' => 'mo.png',
        'Consultants' => 'consultants.png',
        'Stakeholders' => 'stakeholders.png',
        'General Manager' => 'operations.png',
        'Nurses' => 'nurse.png',
        'Clincial psychologist' => 'psychologist.png',
        'Physiotherapists' => 'physiotherapists.png',
        'Pharmacist' => 'pharmacist.png',
        'Receptionists' => 'receptionists.png',
        'Housekeeping' => 'housekeeping.png',
        'Lab & Radiology' => 'lactech.png',
        'OT Assistants' => 'techs.png',
      ];

      // Adjust grid classes for responsiveness
      foreach ($roles as $role => $logo) {
          echo '<div class="col s12 m6 l4">';
          echo '  <a href="login.php?role=' . urlencode($role) . '" class="group-card">';
          // Note: The image path is corrected to work from the root
          echo '    <img src="/media/' . $logo . '" alt="' . htmlspecialchars($role) . '" class="responsive-img">';
          echo '    <h5>' . htmlspecialchars($role) . '</h5>';
          echo '  </a>';
          echo '</div>';
      }
      ?>
    </div>
  </div>

  <?php include_once __DIR__ . '/includes/footer.php'; ?>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>

  <script type="importmap">
      {
          "imports": {
              "three": "https://unpkg.com/three@0.164.1/build/three.module.js"
          }
      }
  </script>
  <script type="module">
      import * as THREE from 'three';

      const scene = new THREE.Scene();
      const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
      const renderer = new THREE.WebGLRenderer({
          canvas: document.querySelector('#dna-canvas'),
          alpha: true
      });
      renderer.setPixelRatio(window.devicePixelRatio);
      renderer.setSize(window.innerWidth, window.innerHeight);
      camera.position.setZ(30);

      const dnaGroup = new THREE.Group();
      const radius = 5, tubeRadius = 0.5, radialSegments = 8, tubularSegments = 64, height = 40, turns = 4;
      
      class HelixCurve extends THREE.Curve {
          constructor(scale = 1, turns = 5, offset = 0) {
              super();
              this.scale = scale; this.turns = turns; this.offset = offset;
          }
          getPoint(t) {
              const tx = Math.cos(this.turns * 2 * Math.PI * t + this.offset);
              const ty = t * height - height / 2;
              const tz = Math.sin(this.turns * 2 * Math.PI * t + this.offset);
              return new THREE.Vector3(tx, ty, tz).multiplyScalar(this.scale);
          }
      }

      const backboneMaterial = new THREE.MeshStandardMaterial({ color: 0x2196f3, metalness: 0.5, roughness: 0.2 });
      const path1 = new HelixCurve(radius, turns, 0);
      const path2 = new HelixCurve(radius, turns, Math.PI);
      dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path1, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));
      dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path2, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));

      const pairMaterial = new THREE.MeshStandardMaterial({ color: 0xffeb3b, metalness: 0.2, roughness: 0.5 });
      const steps = 50;
      for (let i = 0; i <= steps; i++) {
          const t = i / steps;
          const point1 = path1.getPoint(t);
          const point2 = path2.getPoint(t);
          
          const direction = new THREE.Vector3().subVectors(point2, point1);
          const rungGeometry = new THREE.CylinderGeometry(0.3, 0.3, direction.length(), 6);
          const rung = new THREE.Mesh(rungGeometry, pairMaterial);
          
          rung.position.copy(point1).add(direction.multiplyScalar(0.5));
          rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), direction.normalize());
          dnaGroup.add(rung);
      }
      scene.add(dnaGroup);
      
      const ambientLight = new THREE.AmbientLight(0xffffff, 0.5);
      scene.add(ambientLight);
      const pointLight = new THREE.PointLight(0xffffff, 1);
      pointLight.position.set(5, 15, 15);
      scene.add(pointLight);

      function animate() {
          requestAnimationFrame(animate);
          dnaGroup.rotation.y += 0.005;
          dnaGroup.rotation.x += 0.001;
          renderer.render(scene, camera);
      }
      animate();

      window.addEventListener('resize', () => {
          camera.aspect = window.innerWidth / window.innerHeight;
          camera.updateProjectionMatrix();
          renderer.setSize(window.innerWidth, window.innerHeight);
      });
  </script>
</body>
</html>