<?php
require_once 'includes/config/config.php';

// Create MySQLi connection (required for mysqli_* functions in the existing code)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Disaster Response Coordination</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ... (all your existing CSS remains unchanged) ... */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* ... keep every style exactly as you had ... */
        .navbar-brand {
            font-weight: bold;
            color: #dc3545 !important;
            font-size: 1.5rem;
        }
        
        .hero-section {
            background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1587923623987-c7e4083beb23');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 120px 0;
            margin-bottom: 50px;
        }
        
        .hero-section h1 {
            font-size: 3rem;
            margin-bottom: 20px;
            font-weight: bold;
        }
        
        .hero-section p {
            font-size: 1.2rem;
            margin-bottom: 30px;
        }
        
        .feature-card {
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .feature-icon {
            font-size: 3rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        
        .incident-card {
            border-left: 4px solid #dc3545;
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        
        .incident-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .severity-high {
            background-color: #dc3545;
            color: white;
        }
        
        .severity-medium {
            background-color: #fd7e14;
            color: white;
        }
        
        .severity-low {
            background-color: #ffc107;
            color: #333;
        }
        
        .alert-card {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #dc3545;
        }
        
        footer {
            background-color: #1a1a2e;
            color: white;
            padding: 50px 0 20px;
            margin-top: 60px;
        }
        
        footer a {
            color: #ff6b6b;
            text-decoration: none;
        }
        
        footer a:hover {
            color: white;
        }
        
        .btn-danger-custom {
            background-color: #dc3545;
            border: none;
            padding: 12px 30px;
            font-weight: bold;
        }
        
        .btn-danger-custom:hover {
            background-color: #c82333;
        }
        
        .btn-outline-danger-custom {
            border: 2px solid #dc3545;
            color: #dc3545;
            padding: 10px 25px;
            font-weight: bold;
        }
        
        .btn-outline-danger-custom:hover {
            background-color: #dc3545;
            color: white;
        }
        
        .section-title {
            margin-bottom: 40px;
            text-align: center;
            position: relative;
            padding-bottom: 15px;
        }
        
        .section-title:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: #dc3545;
        }
        
        .emergency-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
            }
            70% {
                transform: scale(1.05);
                box-shadow: 0 0 0 15px rgba(220, 53, 69, 0);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }
        
        @media (max-width: 768px) {
            .hero-section {
                padding: 60px 0;
            }
            
            .hero-section h1 {
                font-size: 2rem;
            }
            
            .emergency-btn {
                bottom: 20px;
                right: 20px;
            }
            
            .emergency-btn .btn {
                padding: 10px 20px;
                font-size: 14px;
            }
        }
        
        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
        }
    </style>
</head>
<body>
    <!-- Emergency Floating Button -->
    <div class="emergency-btn">
        <a href="report_emergency.php" class="btn btn-danger btn-lg rounded-pill shadow-lg">
            <i class="fas fa-exclamation-triangle me-2"></i>REPORT EMERGENCY
        </a>
    </div>

    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-hands-helping me-2"></i><?php echo APP_NAME; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="live_map.php">Live Map</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="volunteer.php">Volunteer</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="resources.php">Resources</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="alerts.php">Alerts</a>
                    </li>
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle"></i> <?php echo $_SESSION['full_name']; ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                                <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <li><a class="dropdown-item" href="my_reports.php"><i class="fas fa-flag me-2"></i>My Reports</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="modules/auth/login.php">
                                <i class="fas fa-sign-in-alt me-1"></i>Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-danger text-white px-3 ms-2" href="modules/auth/register.php">
                                <i class="fas fa-user-plus me-1"></i>Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section text-center">
        <div class="container">
            <h1>Disaster Response & Resource Coordination System</h1>
            <p>Real-time coordination platform connecting victims, volunteers, responders, and NGOs for faster disaster response</p>
            <?php if(!isset($_SESSION['user_id'])): ?>
                <a href="register.php" class="btn btn-danger-custom btn-lg">
                    <i class="fas fa-hand-holding-heart me-2"></i>Join the Response Team
                </a>
            <?php else: ?>
                <a href="report_emergency.php" class="btn btn-danger-custom btn-lg">
                    <i class="fas fa-exclamation-triangle me-2"></i>Report Emergency
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Features Section -->
    <section class="container py-5">
        <h2 class="section-title">Our Services</h2>
        <div class="row">
            <div class="col-md-4">
                <div class="card feature-card text-center p-4">
                    <div class="feature-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <h5>Real-time Emergency Reporting</h5>
                    <p>Report emergencies with GPS location, photos, and details. Get immediate response and tracking.</p>
                    <a href="report_emergency.php" class="btn btn-outline-danger mt-2">Report Now →</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card text-center p-4">
                    <div class="feature-icon">
                        <i class="fas fa-map"></i>
                    </div>
                    <h5>Live Interactive Maps</h5>
                    <p>View danger zones, safe centers, evacuation routes, and resource distribution points in real-time.</p>
                    <a href="live_map.php" class="btn btn-outline-danger mt-2">View Map →</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card text-center p-4">
                    <div class="feature-icon">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <h5>Volunteer Coordination</h5>
                    <p>Register your skills, mark availability, and get matched with incidents needing your expertise.</p>
                    <a href="volunteer.php" class="btn btn-outline-danger mt-2">Join as Volunteer →</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Active Incidents Section -->
    <section class="bg-light py-5">
        <div class="container">
            <h2 class="section-title">Active Incidents</h2>
            <div class="row">
                <?php
                $query = "SELECT i.*, u.full_name as reporter_name 
                          FROM incidents i 
                          JOIN users u ON i.reporter_id = u.id 
                          WHERE i.status IN ('reported', 'verified', 'dispatched') 
                          ORDER BY i.reported_at DESC 
                          LIMIT 6";
                $result = mysqli_query($conn, $query);
                
                if(mysqli_num_rows($result) > 0) {
                    while($row = mysqli_fetch_assoc($result)) {
                        $severity_class = ($row['severity'] == 'critical') ? 'severity-high' : (($row['severity'] == 'high') ? 'severity-high' : (($row['severity'] == 'medium') ? 'severity-medium' : 'severity-low'));
                        $severity_text = strtoupper($row['severity']);
                        
                        echo '<div class="col-md-4">
                                <div class="card incident-card">
                                    <div class="card-body position-relative">
                                        <div class="status-badge">
                                            <span class="badge ' . $severity_class . '">' . $severity_text . '</span>
                                        </div>
                                        <h5 class="card-title mb-2">
                                            <i class="fas fa-exclamation-triangle text-danger me-2"></i>' . ucfirst($row['incident_type']) . '
                                        </h5>
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-map-marker-alt me-1"></i> ' . $row['location_name'] . '
                                        </p>
                                        <p class="mb-2">' . substr($row['description'], 0, 100) . '...</p>
                                        <div class="d-flex justify-content-between align-items-center mt-3">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i> ' . date('F j, H:i', strtotime($row['reported_at'])) . '
                                            </small>
                                            <span class="badge bg-secondary">' . ucfirst($row['status']) . '</span>
                                        </div>
                                    </div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div class="col-12">
                            <div class="alert alert-success text-center">
                                <i class="fas fa-check-circle me-2"></i>No active incidents at the moment. Stay safe!
                            </div>
                          </div>';
                }
                ?>
            </div>
            <div class="text-center mt-4">
                <a href="all_incidents.php" class="btn btn-danger">View All Incidents <i class="fas fa-arrow-right ms-2"></i></a>
            </div>
        </div>
    </section>

    <!-- Emergency Alerts Section -->
    <section class="container py-5">
        <h2 class="section-title">Emergency Alerts</h2>
        <div class="row">
            <?php
            $query = "SELECT * FROM alerts 
                      WHERE expires_at >= NOW() 
                      ORDER BY created_at DESC 
                      LIMIT 3";
            $result = mysqli_query($conn, $query);
            
            if(mysqli_num_rows($result) > 0) {
                while($row = mysqli_fetch_assoc($result)) {
                    $alert_class = ($row['alert_type'] == 'danger' || $row['alert_type'] == 'evacuation') ? 'alert-card' : 'bg-warning text-dark';
                    echo '<div class="col-md-4">
                            <div class="card ' . $alert_class . '">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-bell me-2"></i>' . ucfirst($row['alert_type']) . ' Alert
                                        </h5>
                                        <i class="fas fa-exclamation-circle fa-2x"></i>
                                    </div>
                                    <p>' . $row['message'] . '</p>
                                    <hr class="bg-white">
                                    <small>
                                        <i class="fas fa-calendar-check me-1"></i>Valid until: ' . date('F j, Y H:i', strtotime($row['expires_at'])) . '
                                    </small>
                                </div>
                            </div>
                          </div>';
                }
            } else {
                echo '<div class="col-12">
                        <div class="alert alert-secondary text-center">
                            <i class="fas fa-shield-alt me-2"></i>No active alerts. Stay vigilant!
                        </div>
                      </div>';
            }
            ?>
        </div>
        <div class="text-center mt-4">
            <a href="alerts.php" class="btn btn-outline-danger">View All Alerts <i class="fas fa-bell ms-2"></i></a>
        </div>
    </section>

    <!-- Resource Availability Section -->
    <section class="bg-light py-5">
        <div class="container">
            <h2 class="section-title">Available Resources</h2>
            <div class="row">
                <?php
                $query = "SELECT resource_type, SUM(quantity) as total_quantity, COUNT(DISTINCT ngo_id) as ngo_count 
                          FROM resources 
                          WHERE status = 'available' 
                          GROUP BY resource_type 
                          LIMIT 6";
                $result = mysqli_query($conn, $query);
                
                if(mysqli_num_rows($result) > 0) {
                    while($row = mysqli_fetch_assoc($result)) {
                        $icons = [
                            'food' => 'fa-utensils',
                            'water' => 'fa-tint',
                            'medicine' => 'fa-capsules',
                            'shelter' => 'fa-home',
                            'clothing' => 'fa-tshirt',
                            'blankets' => 'fa-bed',
                            'first_aid' => 'fa-medkit',
                            'transport' => 'fa-truck'
                        ];
                        $icon = isset($icons[$row['resource_type']]) ? $icons[$row['resource_type']] : 'fa-box';
                        
                        echo '<div class="col-md-4 mb-3">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas ' . $icon . ' fa-2x text-danger mb-2"></i>
                                                <h5 class="card-title mb-0">' . ucfirst($row['resource_type']) . '</h5>
                                            </div>
                                            <div class="text-end">
                                                <div class="stat-number" style="font-size: 1.8rem;">' . number_format($row['total_quantity']) . '</div>
                                                <small class="text-muted">units available</small>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-building me-1"></i> ' . $row['ngo_count'] . ' NGOs supplying
                                            </small>
                                        </div>
                                    </div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div class="col-12">
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle me-2"></i>Resource inventory being updated. Check back soon!
                            </div>
                          </div>';
                }
                ?>
            </div>
            <div class="text-center mt-4">
                <a href="resources.php" class="btn btn-danger">View All Resources <i class="fas fa-arrow-right ms-2"></i></a>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="bg-danger text-white py-5">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-3 mb-3">
                    <div class="stat-number text-white">24/7</div>
                    <p>Emergency Response</p>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-number text-white">500+</div>
                    <p>Volunteers Registered</p>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-number text-white">50+</div>
                    <p>NGO Partners</p>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-number text-white">1000+</div>
                    <p>Lives Impacted</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section class="container py-5 text-center">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <h3 class="mb-3">Every Second Counts in a Disaster</h3>
                <p class="text-muted mb-4">Join our network of responders, volunteers, and organizations working together to save lives and coordinate effective disaster response.</p>
                <?php if(!isset($_SESSION['user_id'])): ?>
                    <a href="register.php" class="btn btn-danger btn-lg">
                        <i class="fas fa-hands-helping me-2"></i>Become a Responder Today
                    </a>
                <?php else: ?>
                    <a href="report_emergency.php" class="btn btn-danger btn-lg">
                        <i class="fas fa-phone-alt me-2"></i>Report an Emergency
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h5><i class="fas fa-hands-helping me-2"></i>DisasterResponse System</h5>
                    <p>Real-time coordination platform for effective disaster management, connecting victims, volunteers, responders, and relief organizations.</p>
                </div>
                <div class="col-md-4 mb-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php"><i class="fas fa-chevron-right me-2"></i>Home</a></li>
                        <li><a href="live_map.php"><i class="fas fa-chevron-right me-2"></i>Live Map</a></li>
                        <li><a href="volunteer.php"><i class="fas fa-chevron-right me-2"></i>Volunteer</a></li>
                        <li><a href="resources.php"><i class="fas fa-chevron-right me-2"></i>Resources</a></li>
                        <li><a href="alerts.php"><i class="fas fa-chevron-right me-2"></i>Alerts</a></li>
                        <li><a href="report_emergency.php"><i class="fas fa-chevron-right me-2"></i>Report Emergency</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-4">
                    <h5>Emergency Contacts</h5>
                    <p><i class="fas fa-phone-alt me-2"></i> National Disaster: 999</p>
                    <p><i class="fas fa-phone-alt me-2"></i> Red Cross: +254 700 123 456</p>
                    <p><i class="fas fa-envelope me-2"></i> emergency@disasterresponse.org</p>
                    <p><i class="fas fa-map-marker-alt me-2"></i> Kabarak University, Kenya</p>
                    <div class="mt-3">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-twitter fa-lg"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-whatsapp fa-lg"></i></a>
                    </div>
                </div>
            </div>
            <hr class="bg-white">
            <div class="text-center">
                <p class="mb-0">&copy; 2026 <?php echo APP_NAME; ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Close the MySQLi connection (added because $conn is now defined)
if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}
?>