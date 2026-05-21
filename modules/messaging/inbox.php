<?php
/**
 * User Inbox
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays all messages received by the user
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only logged-in users can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

$user_id = $_SESSION['user_id'];
$success = null;
$error = null;

// Handle message actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Mark as read
    if ($_POST['action'] === 'mark_read') {
        $message_id = (int)$_POST['message_id'];
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = ? AND receiver_id = ?");
        $stmt->execute([$message_id, $user_id]);
        $success = "Message marked as read.";
    }
    
    // Delete message
    if ($_POST['action'] === 'delete') {
        $message_id = (int)$_POST['message_id'];
        $stmt = $pdo->prepare("UPDATE messages SET deleted_by_receiver = 1 WHERE id = ? AND receiver_id = ?");
        $stmt->execute([$message_id, $user_id]);
        $success = "Message deleted.";
    }
    
    // Reply to message
    if ($_POST['action'] === 'reply') {
        $original_id = (int)$_POST['original_id'];
        $reply_message = trim($_POST['reply_message']);
        $receiver_id = (int)$_POST['receiver_id'];
        
        if (empty($reply_message)) {
            $error = "Please enter a reply message.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, subject, message, parent_id, sent_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $receiver_id, "Re: " . $_POST['original_subject'], $reply_message, $original_id]);
            $success = "Reply sent successfully!";
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filter
$filter = $_GET['filter'] ?? 'inbox'; // inbox, sent, unread

if ($filter === 'sent') {
    $sql = "
        SELECT m.*, u.full_name as other_user_name, u.role as other_user_role, u.id as other_user_id
        FROM messages m
        JOIN users u ON m.receiver_id = u.id
        WHERE m.sender_id = ? AND m.deleted_by_sender = 0
        ORDER BY m.sent_at DESC
        LIMIT $offset, $per_page
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $messages = $stmt->fetchAll();
    
    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM messages WHERE sender_id = ? AND deleted_by_sender = 0");
    $stmt->execute([$user_id]);
    $total = $stmt->fetch()['total'];
} else {
    $unread_only = ($filter === 'unread');
    $sql = "
        SELECT m.*, u.full_name as other_user_name, u.role as other_user_role, u.id as other_user_id
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.receiver_id = ? AND m.deleted_by_receiver = 0
        " . ($unread_only ? "AND m.is_read = 0" : "") . "
        ORDER BY m.is_read ASC, m.sent_at DESC
        LIMIT $offset, $per_page
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $messages = $stmt->fetchAll();
    
    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM messages WHERE receiver_id = ? AND deleted_by_receiver = 0");
    $stmt->execute([$user_id]);
    $total = $stmt->fetch()['total'];
}

$total_pages = ceil($total / $per_page);

// Get unread count for badge
$stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM messages WHERE receiver_id = ? AND is_read = 0 AND deleted_by_receiver = 0");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetch()['unread'];

$role_badge_colors = [
    'admin' => 'danger',
    'responder' => 'warning',
    'volunteer' => 'info',
    'victim' => 'secondary'
];

$page_title = 'Inbox';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inbox — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
.page { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── STAT CARD (Message Stats) ───────────────────────────── */
.stats-bar {
  display: flex;
  gap: .75rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
}
.stat-chip {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 30px;
  padding: .45rem 1rem;
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  box-shadow: var(--shadow);
}
.stat-chip i { font-size: 1rem; }
.stat-chip .stat-num {
  font-family: var(--ff-head);
  font-weight: 700;
  font-size: 1.1rem;
  color: var(--text);
}
.stat-chip .stat-label {
  font-size: .7rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .05em;
}

/* ─── FILTER PILLS ────────────────────────────────────────── */
.filter-pills {
  display: flex;
  gap: .5rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
}
.filter-pill {
  padding: .4rem 1rem;
  border-radius: 30px;
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--muted);
  text-decoration: none;
  font-family: var(--ff-body);
  font-size: .78rem;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  transition: all var(--ease);
}
.filter-pill i { font-size: .85rem; }
.filter-pill:hover { border-color: var(--red); color: var(--red); background: var(--red-light); }
.filter-pill.active { background: var(--red); border-color: var(--red); color: #fff; }
.filter-badge {
  background: var(--red);
  color: #fff;
  border-radius: 20px;
  padding: .1rem .5rem;
  font-size: .65rem;
  font-weight: 700;
  margin-left: .3rem;
}

/* ─── MESSAGE CARD ────────────────────────────────────────── */
.message-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  margin-bottom: .75rem;
  transition: all var(--ease);
  cursor: pointer;
  box-shadow: var(--shadow);
  overflow: hidden;
}
.message-card:hover { transform: translateX(4px); border-color: var(--red); box-shadow: var(--shadow-lg); }
.message-card.unread { border-left: 4px solid var(--red); background: var(--surface-2); }

.message-body { padding: 1rem 1.25rem; }
.message-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: .5rem; margin-bottom: .5rem; }
.sender-info { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.sender-avatar {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--blue-light); color: var(--blue);
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
}
.sender-name {
  font-weight: 700; font-size: .9rem; color: var(--text);
  display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
}
.role-badge {
  font-size: .65rem; font-weight: 600; text-transform: uppercase;
  padding: .15rem .6rem; border-radius: 4px;
}
.role-badge.admin     { background: var(--red-light); color: var(--red); }
.role-badge.responder { background: var(--amber-light); color: var(--amber); }
.role-badge.volunteer { background: var(--green-light); color: var(--green); }
.role-badge.victim    { background: var(--purple-light); color: var(--purple); }
.message-date {
  font-family: var(--ff-mono); font-size: .7rem; color: var(--muted-2);
  white-space: nowrap;
}
.message-subject {
  font-weight: 600; font-size: .85rem; color: var(--text);
  margin-bottom: .3rem;
}
.message-preview {
  font-size: .78rem; color: var(--text-2);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.dropdown-icon {
  background: transparent;
  border: none;
  color: var(--muted-2);
  width: 30px; height: 30px;
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  transition: all var(--ease);
}
.dropdown-icon:hover { background: var(--surface-2); color: var(--red); }

/* ─── PAGINATION ──────────────────────────────────────────── */
.pagination {
  margin-top: 1rem;
  gap: .3rem;
}
.pagination .page-link {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--muted);
  font-family: var(--ff-mono);
  font-size: .75rem;
  padding: .4rem .8rem;
  border-radius: 6px;
}
.pagination .active .page-link {
  background: var(--red);
  border-color: var(--red);
  color: #fff;
}
.pagination .page-link:hover {
  background: var(--navy);
  color: #fff;
  border-color: var(--navy);
}

/* ─── MODAL ───────────────────────────────────────────────── */
.modal-content {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
}
.modal-header {
  border-bottom-color: var(--border);
  padding: 1rem 1.25rem;
}
.modal-footer {
  border-top-color: var(--border);
  padding: .9rem 1.25rem;
}
.modal-body {
  padding: 1.25rem;
}
.message-detail-sender {
  display: flex; align-items: center; gap: .8rem;
  padding: .8rem;
  background: var(--surface-2);
  border-radius: var(--r);
  margin-bottom: 1rem;
}
.message-detail-content {
  font-size: .85rem;
  line-height: 1.5;
  color: var(--text-2);
  white-space: pre-wrap;
}
.reply-section {
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--border);
}
.reply-section label {
  font-family: var(--ff-head);
  font-size: .75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: .5rem;
}
.reply-textarea {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: .7rem;
  font-size: .8rem;
  color: var(--text);
  width: 100%;
  resize: vertical;
}
.reply-textarea:focus { outline: none; border-color: var(--blue); }

/* ─── EMPTY STATE ─────────────────────────────────────────── */
.empty-state {
  text-align: center; padding: 3rem 1rem; color: var(--muted-2);
}
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .3; }
.empty-state p { font-size: .85rem; margin-bottom: 1rem; }

/* ─── BUTTONS ─────────────────────────────────────────────── */
.btn-new-message {
  background: var(--red);
  border: none;
  padding: .5rem 1rem;
  font-family: var(--ff-head);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  font-size: .8rem;
  border-radius: 30px;
}
.btn-new-message:hover { background: #c82333; transform: translateY(-1px); }

/* ─── ANIMATIONS ──────────────────────────────────────────── */
@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.message-card { animation: fadeUp .3s ease-out; }

/* ─── RESPONSIVE ──────────────────────────────────────────── */
@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .message-header { flex-direction: column; align-items: flex-start; }
  .sender-info { flex-wrap: wrap; }
  .message-date { align-self: flex-end; }
  .stats-bar { justify-content: space-between; }
  .stat-chip { padding: .3rem .7rem; }
  .stat-chip .stat-num { font-size: .9rem; }
  .stat-chip .stat-label { font-size: .6rem; }
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
    <a href="inbox.php" class="npill active"><i class="bi bi-inbox"></i> Inbox</a>
    <a href="compose.php" class="npill"><i class="bi bi-pencil-square"></i> Compose</a>
    <a href="group.php" class="npill"><i class="bi bi-people"></i> Group Chat</a>
  </div>
  <a href="../dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-chat-dots-fill me-1"></i>Communication Hub</div>
    <div class="hero-title">Messages</div>
    <div class="hero-sub">Communicate with team members and responders</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- Stats Bar -->
  <div class="stats-bar">
    <div class="stat-chip">
      <i class="bi bi-envelope-fill text-blue"></i>
      <span class="stat-num"><?= number_format($total) ?></span>
      <span class="stat-label">Total Messages</span>
    </div>
    <div class="stat-chip">
      <i class="bi bi-envelope-exclamation-fill text-amber"></i>
      <span class="stat-num"><?= number_format($unread_count) ?></span>
      <span class="stat-label">Unread</span>
    </div>
    <div style="flex:1"></div>
    <a href="compose.php" class="btn-new-message btn btn-danger">
      <i class="bi bi-pencil-square me-1"></i>New Message
    </a>
  </div>

  <!-- Filter Pills -->
  <div class="filter-pills">
    <a href="?filter=inbox" class="filter-pill <?= $filter == 'inbox' ? 'active' : '' ?>">
      <i class="bi bi-inbox"></i> Inbox
      <?php if ($unread_count > 0): ?>
        <span class="filter-badge"><?= $unread_count ?></span>
      <?php endif; ?>
    </a>
    <a href="?filter=unread" class="filter-pill <?= $filter == 'unread' ? 'active' : '' ?>">
      <i class="bi bi-envelope-exclamation"></i> Unread
    </a>
    <a href="?filter=sent" class="filter-pill <?= $filter == 'sent' ? 'active' : '' ?>">
      <i class="bi bi-send"></i> Sent
    </a>
  </div>

  <!-- Messages List -->
  <?php if (count($messages) > 0): ?>
    <?php foreach ($messages as $msg): ?>
      <div class="message-card <?= (!$msg['is_read'] && $filter != 'sent') ? 'unread' : '' ?>" 
           data-id="<?= $msg['id'] ?>"
           data-sender="<?= htmlspecialchars($msg['other_user_name']) ?>"
           data-sender-id="<?= $msg['other_user_id'] ?>"
           data-subject="<?= htmlspecialchars($msg['subject']) ?>"
           data-message="<?= htmlspecialchars($msg['message']) ?>"
           data-date="<?= date('F j, Y g:i A', strtotime($msg['sent_at'])) ?>">
        <div class="message-body">
          <div class="message-header">
            <div class="sender-info">
              <div class="sender-avatar">
                <i class="bi bi-person-fill"></i>
              </div>
              <div>
                <div class="sender-name">
                  <?= htmlspecialchars($msg['other_user_name']) ?>
                  <span class="role-badge <?= $msg['other_user_role'] ?>">
                    <?= ucfirst($msg['other_user_role']) ?>
                  </span>
                </div>
              </div>
            </div>
            <div class="message-date">
              <i class="bi bi-clock me-1"></i><?= date('M j, H:i', strtotime($msg['sent_at'])) ?>
            </div>
          </div>
          <div class="message-subject"><?= htmlspecialchars($msg['subject']) ?></div>
          <div class="message-preview"><?= htmlspecialchars(substr($msg['message'], 0, 120)) ?>...</div>
        </div>
        <div class="dropdown position-absolute" style="top: 1rem; right: 1rem;">
          <button class="dropdown-icon" data-bs-toggle="dropdown">
            <i class="bi bi-three-dots-vertical"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end bg-dark border-secondary">
            <li><a class="dropdown-item text-white" href="#" onclick="markRead(<?= $msg['id'] ?>)"><i class="bi bi-check2-circle me-2"></i>Mark as Read</a></li>
            <li><a class="dropdown-item text-white" href="#" onclick="replyTo(<?= $msg['other_user_id'] ?>, '<?= htmlspecialchars(addslashes($msg['subject'])) ?>')"><i class="bi bi-reply me-2"></i>Reply</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="deleteMessage(<?= $msg['id'] ?>)"><i class="bi bi-trash me-2"></i>Delete</a></li>
          </ul>
        </div>
      </div>
    <?php endforeach; ?>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-center mt-4">
      <nav>
        <ul class="pagination">
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $i ?>&filter=<?= $filter ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
    
  <?php else: ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <p>No messages found.</p>
      <a href="compose.php" class="btn btn-danger rounded-pill">
        <i class="bi bi-pencil-square me-2"></i>Send First Message
      </a>
    </div>
  <?php endif; ?>
</div>

<!-- Message Detail Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="modalSubject" style="font-family:var(--ff-head);font-weight:700"></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="message-detail-sender">
          <i class="bi bi-person-circle fs-3 text-muted"></i>
          <div>
            <div id="modalSender" class="fw-semibold" style="font-size:.9rem"></div>
            <div id="modalDate" class="small text-muted" style="font-family:var(--ff-mono)"></div>
          </div>
        </div>
        <div id="modalMessage" class="message-detail-content"></div>
        
        <!-- Reply Form -->
        <div id="replySection" class="reply-section" style="display: none;">
          <label><i class="bi bi-reply-fill me-1"></i>Reply to this message</label>
          <form method="POST">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="original_id" id="replyOriginalId">
            <input type="hidden" name="receiver_id" id="replyReceiverId">
            <input type="hidden" name="original_subject" id="replyOriginalSubject">
            <textarea name="reply_message" class="reply-textarea" rows="3" placeholder="Type your reply..."></textarea>
            <button type="submit" class="btn btn-danger btn-sm mt-2 rounded-pill px-3">Send Reply</button>
          </form>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// View message details
document.querySelectorAll('.message-card').forEach(card => {
  card.addEventListener('click', function(e) {
    if (e.target.closest('.dropdown') || e.target.closest('.dropdown-menu')) return;
    
    const id = this.dataset.id;
    const sender = this.dataset.sender;
    const subject = this.dataset.subject;
    const message = this.dataset.message;
    const date = this.dataset.date;
    const senderId = this.dataset.senderId;
    
    document.getElementById('modalSubject').textContent = subject;
    document.getElementById('modalSender').innerHTML = sender;
    document.getElementById('modalDate').textContent = date;
    document.getElementById('modalMessage').innerHTML = message.replace(/\n/g, '<br>');
    
    // Reset reply section
    document.getElementById('replySection').style.display = 'none';
    document.getElementById('replyOriginalId').value = '';
    document.getElementById('replyReceiverId').value = '';
    document.getElementById('replyOriginalSubject').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('messageModal'));
    modal.show();
  });
});

function markRead(id) {
  fetch('inbox.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=mark_read&message_id=${id}`
  }).then(() => location.reload());
}

function deleteMessage(id) {
  if (confirm('Delete this message?')) {
    fetch('inbox.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=delete&message_id=${id}`
    }).then(() => location.reload());
  }
}

function replyTo(userId, subject) {
  document.getElementById('replyOriginalId').value = '';
  document.getElementById('replyReceiverId').value = userId;
  document.getElementById('replyOriginalSubject').value = subject;
  document.getElementById('replySection').style.display = 'block';
  document.getElementById('replySection').scrollIntoView({ behavior: 'smooth' });
}
</script>
</body>
</html>