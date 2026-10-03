<?php
/**
 * Signed-out landing page.
 *
 * A complete standalone document, not a fragment: it is rendered with an empty
 * layout, because there is no session and therefore no nav bar to extend, and
 * because a CDN is faster than a local copy of Bootstrap for a page most
 * visitors see exactly once.
 *
 * @var string $appShort
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>CAHU - Cooperative Agribusiness Hub Uganda</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --cahu-green: #2E7D32;
            --cahu-light-green: #4CAF50;
            --cahu-dark: #1b5e20;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #4a4a4a;
        }
        .navbar-brand {
            font-weight: 700;
            color: var(--cahu-green) !important;
            letter-spacing: 1px;
        }
        .hero-section {
            background: linear-gradient(rgba(27, 94, 32, 0.8), rgba(46, 125, 50, 0.9)), url('https://images.unsplash.com/photo-1592982537447-6f296317bba5?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') center/cover no-repeat;
            color: white;
            padding: 100px 0;
            text-align: center;
        }
        .hero-section h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 20px;
        }
        .hero-section p {
            font-size: 1.2rem;
            margin-bottom: 40px;
            font-weight: 300;
        }
        .btn-custom {
            background-color: white;
            color: var(--cahu-green);
            padding: 12px 30px;
            font-weight: bold;
            border-radius: 50px;
            transition: all 0.3s;
        }
        .btn-custom:hover {
            background-color: var(--cahu-light-green);
            color: white;
            border-color: var(--cahu-light-green);
        }
        .btn-outline-custom {
            border: 2px solid white;
            color: white;
            padding: 12px 30px;
            font-weight: bold;
            border-radius: 50px;
            transition: all 0.3s;
        }
        .btn-outline-custom:hover {
            background-color: white;
            color: var(--cahu-green);
        }
        .features-section {
            padding: 80px 0;
            background-color: #f9f9f9;
        }
        .feature-card {
            background: white;
            border: none;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
        }
        .feature-card:hover {
            transform: translateY(-5px);
        }
        .feature-icon {
            font-size: 40px;
            color: var(--cahu-green);
            margin-bottom: 20px;
        }
        .footer {
            background-color: #1a1a1a;
            color: #999;
            padding: 40px 0 20px;
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand" href="<?= url('') ?>">
                <i class="fas fa-leaf me-2"></i>CAHU PLATFORM
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item me-3">
                        <a class="nav-link text-dark fw-bold" href="<?= url('login') ?>">Sign In</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-success rounded-pill px-4" href="<?= url('register') ?>">Join Platform</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1>Complete Agribusiness Traceability</h1>
                    <p>Empowering Ugandan farmers with precision mapping, crop lifecycle tracking, offline field operations, and secure market linkage.</p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="<?= url('register') ?>" class="btn btn-custom btn-lg">Get Started</a>
                        <a href="#features" class="btn btn-outline-custom btn-lg">Learn More</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="features-section">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-dark">Enterprise-Grade Field Operations</h2>
                <p class="text-muted">Built exclusively for agricultural managers and field officers to digitize the entire farm-to-export chain.</p>
            </div>
            
            <div class="row g-4">
                <!-- Feature 1 -->
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <div class="feature-icon"><i class="fas fa-map-marked-alt"></i></div>
                        <h4 class="fw-bold h5">GPS Garden Mapping</h4>
                        <p class="text-muted mb-0">Pinpoint exact garden acreages dynamically using Leaflet mapping and offline Flutter field apps.</p>
                    </div>
                </div>
                <!-- Feature 2 -->
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <div class="feature-icon"><i class="fas fa-seedling"></i></div>
                        <h4 class="fw-bold h5">Crop Lifecycle Tracking</h4>
                        <p class="text-muted mb-0">Monitor plantings, schedule activities, and track chemical spraying intervals in strict adherence to export standards.</p>
                    </div>
                </div>
                <!-- Feature 3 -->
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                        <h4 class="fw-bold h5">Harvest Forecasting</h4>
                        <p class="text-muted mb-0">Anticipate market yields utilizing algorithmic yield predictions generated straight from active planting data.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer text-center">
        <div class="container">
            <p>&copy; <?= date('Y') ?> Cooperative Agribusiness Hub Uganda. Building the future of agriculture.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
