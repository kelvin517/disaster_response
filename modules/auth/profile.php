<?php
// Profile Management (View/Edit/Change Password)
require_once __DIR__ . '/../../includes/config/config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    $_SESSION['message'] = "Please login to access your profile.";
    redirect('modules/auth/login.php');
}

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

// Fetch current user data
$stmt = $pdo->prepare("SELECT full_name, email, phone, role, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    logout(); // invalid user
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Update basic info
    if ($action === 'update_profile') {
        $full_name = sanitize($_POST['full_name']);
        $phone = sanitize($_POST['phone']);

        if (empty($full_name)) {
            $error = "Full name is required.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
            if ($stmt->execute([$full_name, $phone, $user_id])) {
                $_SESSION['full_name'] = $full_name; // update session
                $success = "Profile updated successfully.";
                // Refresh user data
                $user['full_name'] = $full_name;
                $user['phone'] = $phone;
            } else {
                $error = "Failed to update profile.";
            }
        }
    }

    // Change password
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $db_pass = $stmt->fetchColumn();

        if (!password_verify($current, $db_pass)) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed, $user_id])) {
                $success = "Password changed successfully.";
            } else {
                $error = "Failed to change password.";
            }
        }
    }

    // Upload profile picture (optional)
    elseif ($action === 'upload_photo' && isset($_FILES['profile_image'])) {
        $file = $_FILES['profile_image'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowed)) {
                $error = "Only JPG, PNG, GIF images are allowed.";
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $error = "File too large. Max 5MB.";
            } else {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
                $destination = UPLOAD_DIR . $filename;
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Delete old image if exists
                    if (!empty($user['profile_image']) && file_exists(UPLOAD_DIR . $user['profile_image'])) {
                        unlink(UPLOAD_DIR . $user['profile_image']);
                    }
                    $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->execute([$filename, $user_id]);
                    $_SESSION['profile_image'] = $filename;
                    $success = "Profile picture updated.";
                    $user['profile_image'] = $filename;
                } else {
                    $error = "Failed to upload image.";
                }
            }
        } else {
            $error = "No file selected or upload error.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; }
        .profile-header { background: linear-gradient(135deg, #dc3545, #b02a37); color: white; padding: 20px; border-radius: 15px 15px 0 0; }
        .profile-img { width: 100px; height: 100px; object-fit: cover; border-radius: 50%; border: 3px solid white; }
        .card { border: none; border-radius: 15px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.1); margin-top: 30px; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="profile-header text-center">
                    <?php if (!empty($user['profile_image']) && file_exists(UPLOAD_DIR . $user['profile_image'])): ?>
                        <img src="<?php echo BASE_URL . 'temp/uploads/' . $user['profile_image']; ?>" class="profile-img mb-2">
                    <?php else: ?>
                        <i class="fas fa-user-circle fa-5x mb-2"></i>
                    <?php endif; ?>
                    <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                    <p class="mb-0"><?php echo ucfirst($user['role']); ?></p>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <ul class="nav nav-tabs" id="profileTab" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#edit">Edit Profile</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#password">Change Password</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#photo">Profile Photo</button></li>
                    </ul>
                    <div class="tab-content mt-3">
                        <!-- Edit Profile Tab -->
                        <div class="tab-pane fade show active" id="edit">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="mb-3">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label>Email</label>
                                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                    <small class="text-muted">Email cannot be changed.</small>
                                </div>
                                <div class="mb-3">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone']); ?>">
                                </div>
                                <button type="submit" class="btn btn-danger">Update Profile</button>
                            </form>
                        </div>
                        <!-- Change Password Tab -->
                        <div class="tab-pane fade" id="password">
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">
                                <div class="mb-3">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-danger">Change Password</button>
                            </form>
                        </div>
                        <!-- Profile Photo Tab -->
                        <div class="tab-pane fade" id="photo">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_photo">
                                <div class="mb-3">
                                    <label>Select Image (JPG, PNG, GIF max 5MB)</label>
                                    <input type="file" name="profile_image" class="form-control" accept="image/*" required>
                                </div>
                                <button type="submit" class="btn btn-danger">Upload Photo</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-center">
                    <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary btn-sm">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>