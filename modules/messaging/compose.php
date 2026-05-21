<?php
/**
 * Compose Message
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows users to compose and send messages to other users
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only logged-in users can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

// Get recipient from URL if provided
$recipient_id = isset($_GET['to']) ? (int)$_GET['to'] : 0;
$recipient_name = '';
if ($recipient_id) {
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$recipient_id]);
    $recipient = $stmt->fetch();
    if ($recipient) {
        $recipient_name = $recipient['full_name'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $receiver_id = (int)$_POST['receiver_id'];
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    
    if (empty($receiver_id)) {
        $error = "Please select a recipient.";
    } elseif (empty($subject)) {
        $error = "Please enter a subject.";
    } elseif (empty($message)) {
        $error = "Please enter a message.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, subject, message, sent_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if ($stmt->execute([$user_id, $receiver_id, $subject, $message])) {
            $_SESSION['success'] = "Message sent successfully!";
            redirect('inbox.php');
        } else {
            $error = "Failed to send message.";
        }
    }
}

// Fetch users for recipient dropdown (exclude current user)
$stmt = $pdo->prepare("
    SELECT id, full_name, role 
    FROM users 
    WHERE id != ? 
    ORDER BY 
        CASE role 
            WHEN 'admin' THEN 1 
            WHEN 'responder' THEN 2 
            WHEN 'volunteer' THEN 3 
            ELSE 4 
        END,
        full_name
");
$stmt->execute([$user_id]);
$users = $stmt->fetchAll();

$role_badge_colors = [
    'admin' => 'danger',
    'responder' => 'warning',
    'volunteer' => 'info',
    'victim' => 'secondary'
];

$page_title = 'Compose Message';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Compose Message — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══ TOKENS ══════════════════════════════════════════════════ */
:root {
  --bg:         #f0f2f5;
  --surface:    #ffffff;
  --surface-2:  #f7f8fa;
  --border:     #e2e5ea;
  --border-2:   #d0d4db;

  --navy:       #0f1b2d;
  --navy-2:     #1a2b42;

  --red:        #e8271d;
  --red-light:  #fff0ef;
  --amber:      #d97706;
  --amber-light:#fffbeb;
  --blue:       #1d6ef5;
  --blue-light: #eff5ff;
  --green:      #16a34a;
  --green-light:#f0fdf4;
  --teal:       #0891b2;
  --teal-light: #ecfeff;
  --purple:     #7c3aed;
  --purple-light:#f5f3ff;

  --text:       #0f1b2d;
  --text-2:     #374151;
  --muted:      #6b7280;
  --muted-2:    #9ca3af;

  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;

  --r:       8px;
  --r-lg:    12px;
  --shadow:  0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --shadow-lg:0 4px 24px rgba(15,27,45,.12);
  --ease:    .18s cubic-bezier(.4,0,.2,1);
}

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
body { font-family: var(--ff-body); background: var(--bg); color: var(--text); font-size: 14px; min-height: 100vh; }

::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 4px; }

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background: var(--navy);
  height: 54px;
  display: flex; align-items: stretch;
  position: sticky; top: 0; z-index: 300;
  box-shadow: 0 2px 12px rgba(15,27,45,.35);
}
.brand {
  display: flex; align-items: center; gap: .5rem;
  padding: 0 2rem 0 1.25rem;
  background: var(--red);
  text-decoration: none;
  clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 100%, 0 100%);
  flex-shrink: 0;
}
.brand-text { font-family: var(--ff-head); font-weight: 800; font-size: 1.1rem; color: #fff; text-transform: uppercase; letter-spacing: .03em; }
.brand-sub  { font-family: var(--ff-mono); font-size: .5rem; font-weight: 600; color: rgba(255,255,255,.65); letter-spacing: .12em; text-transform: uppercase; display: block; margin-top: -2px; }
.nav-area {
  display: flex; align-items: center; padding: 0 .75rem; gap: .1rem; flex: 1;
  overflow-x: auto;
}
.nav-area::-webkit-scrollbar { height:0; }
.npill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .75rem; border-radius: 5px;
  color: rgba(255,255,255,.6); font-size: .78rem; font-weight: 500;
  text-decoration: none; white-space: nowrap;
  transition: all var(--ease);
}
.npill:hover { color: #fff; background: rgba(255,255,255,.1); }
.npill.active { color: #fff; background: rgba(255,255,255,.15); }
.npill i { font-size: .85rem; }
.logout-btn {
  display: flex; align-items: center; gap: .3rem;
  margin: auto 1.25rem;
  padding: .3rem .7rem; border-radius: 5px;
  border: 1px solid rgba(232,39,29,.4); background: rgba(232,39,29,.12);
  color: #ff7a74; font-size: .75rem; font-weight: 600;
  text-decoration: none; transition: all var(--ease); white-space: nowrap;
}
.logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── HERO HEADER ─────────────────────────────────────────── */
.page-hero {
  background: var(--navy);
  padding: 1.4rem 0;
  border-bottom: 3px solid var(--red);
  position: relative; overflow: hidden;
}
.page-hero::before {
  content: '';
  position: absolute; right: -60px; top: -60px;
  width: 280px; height: 280px;
  background: radial-gradient(circle, rgba(232,39,29,.12) 0%, transparent 65%);
  pointer-events: none;
}
.hero-eyebrow {
  font-family: var(--ff-mono); font-size: .62rem; font-weight: 600;
  letter-spacing: .16em; text-transform: uppercase;
  color: var(--red); margin-bottom: .3rem;
}
.hero-title {
  font-family: var(--ff-head); font-weight: 800; font-size: 1.8rem;
  color: #fff; letter-spacing: .02em; text-transform: uppercase; line-height: 1.1;
}
.hero-sub { color: rgba(255,255,255,.45); font-size: .8rem; margin-top: .25rem; font-family: var(--ff-mono); }

/* ─── PAGE ────────────────────────────────────────────────── */
.page { max-width: 900px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── COMPOSE CARD ────────────────────────────────────────── */
.compose-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  overflow: hidden;
}
.compose-header {
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  gap: .6rem;
}
.compose-header-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--red-light); color: var(--red);
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
}
.compose-header-title {
  font-family: var(--ff-head);
  font-size: .85rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--text-2);
}
.compose-body { padding: 1.5rem; }

/* Recipient Search */
.recipient-search {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: .6rem 1rem;
  width: 100%;
  font-family: var(--ff-body);
  font-size: .85rem;
  color: var(--text);
  outline: none;
  transition: all var(--ease);
}
.recipient-search:focus { border-color: var(--blue); }

.user-list {
  max-height: 240px;
  overflow-y: auto;
  margin-top: .5rem;
  border: 1px solid var(--border);
  border-radius: var(--r);
  background: var(--surface);
}
.user-item {
  padding: .7rem 1rem;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  transition: all var(--ease);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.user-item:last-child { border-bottom: none; }
.user-item:hover { background: var(--surface-2); }
.user-item.selected { background: var(--red-light); border-left: 3px solid var(--red); }
.user-name {
  font-weight: 600;
  font-size: .85rem;
  color: var(--text);
}
.role-badge {
  font-size: .6rem;
  font-weight: 700;
  text-transform: uppercase;
  padding: .2rem .6rem;
  border-radius: 4px;
  margin-left: .5rem;
}
.role-badge.admin     { background: var(--red-light); color: var(--red); }
.role-badge.responder { background: var(--amber-light); color: var(--amber); }
.role-badge.volunteer { background: var(--green-light); color: var(--green); }
.role-badge.victim    { background: var(--purple-light); color: var(--purple); }
.check-icon { color: var(--green); font-size: .9rem; display: none; }
.user-item.selected .check-icon { display: inline-block; }

/* Form Fields */
.form-field {
  margin-bottom: 1.25rem;
}
.form-label {
  font-family: var(--ff-head);
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  display: block;
  margin-bottom: .4rem;
}
.form-input {
  width: 100%;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: .7rem 1rem;
  font-family: var(--ff-body);
  font-size: .85rem;
  color: var(--text);
  outline: none;
  transition: all var(--ease);
}
.form-input:focus { border-color: var(--blue); }
.form-textarea {
  width: 100%;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: .7rem 1rem;
  font-family: var(--ff-body);
  font-size: .85rem;
  color: var(--text);
  outline: none;
  resize: vertical;
  min-height: 150px;
}
.form-textarea:focus { border-color: var(--blue); }
.char-counter {
  text-align: right;
  margin-top: .3rem;
  font-family: var(--ff-mono);
  font-size: .65rem;
  color: var(--muted-2);
}

/* Buttons */
.btn-send {
  background: var(--red);
  border: none;
  padding: .8rem;
  font-family: var(--ff-head);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  font-size: .85rem;
  border-radius: var(--r);
  width: 100%;
  transition: all var(--ease);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem;
}
.btn-send:hover { background: #c82333; transform: translateY(-1px); }
.btn-cancel {
  background: transparent;
  border: 1px solid var(--border);
  padding: .7rem;
  font-family: var(--ff-body);
  font-size: .8rem;
  border-radius: var(--r);
  width: 100%;
  text-align: center;
  text-decoration: none;
  color: var(--muted);
  transition: all var(--ease);
  display: block;
}
.btn-cancel:hover { border-color: var(--red); color: var(--red); background: var(--red-light); }

/* Alert */
.alert-custom {
  border-radius: var(--r);
  padding: .8rem 1rem;
  margin-bottom: 1rem;
  font-size: .8rem;
}
.alert-danger { background: var(--red-light); color: var(--red); border: 1px solid rgba(232,39,29,.2); }

/* Empty State */
.empty-state {
  text-align: center; padding: 2rem; color: var(--muted-2);
}
.empty-state i { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .3; }

@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.compose-card { animation: fadeUp .3s ease-out; }

@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .compose-body { padding: 1rem; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="inbox.php">
    <i class="bi bi-envelope-fill" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Message Center</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="inbox.php" class="npill"><i class="bi bi-inbox"></i> Inbox</a>
    <a href="compose.php" class="npill active"><i class="bi bi-pencil-square"></i> Compose</a>
    <a href="group.php" class="npill"><i class="bi bi-people"></i> Group Chat</a>
  </div>
  <a href="../admin/dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-pencil-square me-1"></i>New Message</div>
    <div class="hero-title">Compose</div>
    <div class="hero-sub">Send a message to another user</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">
  <div class="compose-card">
    <div class="compose-header">
      <div class="compose-header-icon"><i class="bi bi-envelope-fill"></i></div>
      <div class="compose-header-title">New Message</div>
    </div>
    <div class="compose-body">
      <?php if ($error): ?>
        <div class="alert-custom alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      
      <form method="POST">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="receiver_id" id="receiver_id" value="<?= $recipient_id ?>">
        
        <!-- Recipient Selection -->
        <div class="form-field">
          <label class="form-label">To:</label>
          <input type="text" id="recipientSearch" class="recipient-search" placeholder="Search users by name..." autocomplete="off">
          <div class="user-list" id="userList">
            <?php foreach ($users as $user): ?>
              <div class="user-item <?= ($recipient_id == $user['id']) ? 'selected' : '' ?>" 
                   data-id="<?= $user['id'] ?>" 
                   data-name="<?= htmlspecialchars($user['full_name']) ?>"
                   data-role="<?= $user['role'] ?>">
                <div>
                  <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                  <span class="role-badge <?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
                </div>
                <i class="bi bi-check-circle-fill check-icon"></i>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        
        <!-- Subject -->
        <div class="form-field">
          <label class="form-label">Subject</label>
          <input type="text" name="subject" class="form-input" placeholder="Enter message subject..." required>
        </div>
        
        <!-- Message -->
        <div class="form-field">
          <label class="form-label">Message</label>
          <textarea name="message" id="message" class="form-textarea" placeholder="Type your message here..." required></textarea>
          <div class="char-counter"><span id="charCount">0</span> characters</div>
        </div>
        
        <div class="d-grid gap-2">
          <button type="submit" class="btn-send">
            <i class="bi bi-send-fill"></i> Send Message
          </button>
          <a href="inbox.php" class="btn-cancel">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// User selection
document.querySelectorAll('.user-item').forEach(item => {
  item.addEventListener('click', function() {
    document.querySelectorAll('.user-item').forEach(i => i.classList.remove('selected'));
    this.classList.add('selected');
    document.getElementById('receiver_id').value = this.dataset.id;
  });
});

// Search users
document.getElementById('recipientSearch').addEventListener('input', function() {
  const search = this.value.toLowerCase();
  document.querySelectorAll('.user-item').forEach(item => {
    const name = item.dataset.name.toLowerCase();
    item.style.display = name.includes(search) ? 'flex' : 'none';
  });
});

// Character counter
const messageTextarea = document.getElementById('message');
messageTextarea.addEventListener('input', function() {
  document.getElementById('charCount').textContent = this.value.length;
});

// Pre-select recipient if provided
<?php if ($recipient_id): ?>
document.querySelector(`.user-item[data-id="<?= $recipient_id ?>"]`).click();
<?php endif; ?>
</script>
</body>
</html>