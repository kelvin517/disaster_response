<?php
/**
 * Group Messaging
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Group chat functionality for operations teams and incident-specific groups
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

// Get active group
$group_id = isset($_GET['group']) ? (int)$_GET['group'] : 0;

// Get available groups for the user
$stmt = $pdo->prepare("
    SELECT DISTINCT g.*, 
           (SELECT COUNT(*) FROM group_messages WHERE group_id = g.id AND created_at > COALESCE(ug.last_read, '1970-01-01')) as unread_count
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN user_groups ug ON ug.group_id = g.id AND ug.user_id = ?
    WHERE gm.user_id = ?
    ORDER BY g.name
");
$stmt->execute([$user_id, $user_id]);
$groups = $stmt->fetchAll();

// If no group selected and there are groups, select the first one
if ($group_id == 0 && count($groups) > 0) {
    $group_id = $groups[0]['id'];
}

// Fetch selected group details
$group = null;
if ($group_id) {
    $stmt = $pdo->prepare("
        SELECT g.*, 
               (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
        FROM groups g
        WHERE g.id = ?
    ");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
}

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $message = trim($_POST['message']);
    $group_id_post = (int)$_POST['group_id'];
    
    if (empty($message)) {
        $error = "Please enter a message.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO group_messages (group_id, user_id, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        if ($stmt->execute([$group_id_post, $user_id, $message])) {
            // Update last read for the sender
            $stmt = $pdo->prepare("
                INSERT INTO user_groups (user_id, group_id, last_read) 
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE last_read = NOW()
            ");
            $stmt->execute([$user_id, $group_id_post]);
            $success = true;
        } else {
            $error = "Failed to send message.";
        }
    }
}

// Mark messages as read when viewing
if ($group_id) {
    $stmt = $pdo->prepare("
        INSERT INTO user_groups (user_id, group_id, last_read) 
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_read = NOW()
    ");
    $stmt->execute([$user_id, $group_id]);
}

// Fetch messages for selected group
$messages = [];
if ($group_id) {
    $stmt = $pdo->prepare("
        SELECT gm.*, u.full_name, u.role, u.id as user_id
        FROM group_messages gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ?
        ORDER BY gm.created_at ASC
        LIMIT 200
    ");
    $stmt->execute([$group_id]);
    $messages = $stmt->fetchAll();
}

// Fetch group members
$members = [];
if ($group_id) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.is_active
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$group_id]);
    $members = $stmt->fetchAll();
}

$role_badge_colors = [
    'admin' => 'danger',
    'responder' => 'warning',
    'volunteer' => 'info',
    'victim' => 'secondary'
];

$page_title = 'Group Chat';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Group Chat — DisasterResponse</title>
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
body { font-family: var(--ff-body); background: var(--bg); color: var(--text); font-size: 14px; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 4px; }

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background: var(--navy);
  height: 54px;
  display: flex; align-items: stretch;
  position: sticky; top: 0; z-index: 300;
  flex-shrink: 0;
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
.logout-btn {
  display: flex; align-items: center; gap: .3rem;
  margin: auto 1.25rem;
  padding: .3rem .7rem; border-radius: 5px;
  border: 1px solid rgba(232,39,29,.4); background: rgba(232,39,29,.12);
  color: #ff7a74; font-size: .75rem; font-weight: 600;
  text-decoration: none; white-space: nowrap;
}
.logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── CHAT CONTAINER ──────────────────────────────────────── */
.chat-container {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* ─── GROUPS SIDEBAR ─────────────────────────────────────── */
.groups-sidebar {
  width: 280px;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  flex-shrink: 0;
}
.sidebar-header {
  padding: 1rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-family: var(--ff-head);
  font-weight: 700;
  font-size: .8rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
}
.group-item {
  padding: .8rem 1rem;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  transition: all var(--ease);
}
.group-item:hover { background: var(--surface-2); }
.group-item.active { background: var(--red-light); border-left: 3px solid var(--red); }
.group-name {
  font-weight: 600;
  font-size: .85rem;
  display: flex;
  align-items: center;
  gap: .5rem;
  color: var(--text);
}
.group-desc {
  font-size: .7rem;
  color: var(--muted-2);
  margin-top: .2rem;
}
.unread-badge {
  background: var(--red);
  color: #fff;
  border-radius: 20px;
  padding: .1rem .5rem;
  font-size: .6rem;
  font-weight: 700;
  margin-left: .5rem;
}

/* ─── CHAT AREA ──────────────────────────────────────────── */
.chat-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--surface);
}
.chat-header {
  padding: 1rem 1.25rem;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: .5rem;
}
.chat-header-title {
  font-weight: 700;
  font-size: 1rem;
  color: var(--text);
}
.chat-header-meta {
  font-size: .7rem;
  color: var(--muted-2);
  font-family: var(--ff-mono);
}
.btn-members {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 30px;
  padding: .3rem .9rem;
  font-size: .7rem;
  color: var(--muted);
  transition: all var(--ease);
}
.btn-members:hover { border-color: var(--red); color: var(--red); background: var(--red-light); }

/* Messages Area */
.messages-area {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: .5rem;
}
.message-bubble {
  display: flex;
  margin-bottom: .5rem;
}
.message-bubble.sent { justify-content: flex-end; }
.message-bubble.received { justify-content: flex-start; }
.bubble {
  max-width: 65%;
  padding: .7rem 1rem;
  border-radius: 18px;
}
.sent .bubble { background: var(--red); color: #fff; border-bottom-right-radius: 4px; }
.received .bubble { background: var(--surface-2); color: var(--text); border-bottom-left-radius: 4px; border: 1px solid var(--border); }
.sender-name {
  font-weight: 600;
  font-size: .7rem;
  margin-bottom: .2rem;
  color: var(--muted-2);
}
.message-text { font-size: .85rem; line-height: 1.4; word-wrap: break-word; }
.message-time {
  font-size: .6rem;
  margin-top: .2rem;
  opacity: .6;
  text-align: right;
}

/* Message Input */
.message-input-area {
  background: var(--surface);
  border-top: 1px solid var(--border);
  padding: .8rem 1.25rem;
}
.message-form {
  display: flex;
  gap: .75rem;
  align-items: flex-end;
}
.message-input {
  flex: 1;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: .7rem 1rem;
  font-family: var(--ff-body);
  font-size: .85rem;
  color: var(--text);
  resize: none;
  outline: none;
  transition: all var(--ease);
  max-height: 100px;
}
.message-input:focus { border-color: var(--red); }
.send-btn {
  background: var(--red);
  border: none;
  border-radius: 30px;
  width: 42px;
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all var(--ease);
}
.send-btn:hover { background: #c82333; transform: scale(1.02); }

/* Empty State */
.empty-chat {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: var(--muted-2);
  text-align: center;
}
.empty-chat i { font-size: 3rem; display: block; margin-bottom: 1rem; opacity: .3; }

/* Modal */
.modal-content {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
}
.modal-header { border-bottom-color: var(--border); }
.member-item {
  display: flex;
  align-items: center;
  gap: .8rem;
  padding: .7rem 0;
  border-bottom: 1px solid var(--border);
}
.member-item:last-child { border-bottom: none; }
.member-avatar {
  width: 36px; height: 36px;
  background: var(--surface-2);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  color: var(--muted);
}
.member-name { font-weight: 600; font-size: .85rem; }
.member-role {
  font-size: .6rem;
  font-weight: 700;
  text-transform: uppercase;
  padding: .15rem .5rem;
  border-radius: 4px;
  margin-left: .5rem;
}

@keyframes fadeSlide {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
.message-bubble { animation: fadeSlide .2s ease-out; }

@media (max-width: 768px) {
  .groups-sidebar { width: 220px; }
  .bubble { max-width: 85%; }
  .chat-header { flex-direction: column; align-items: flex-start; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="group.php">
    <i class="bi bi-chat-dots-fill" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Team Chat</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="inbox.php" class="npill"><i class="bi bi-inbox"></i> Inbox</a>
    <a href="compose.php" class="npill"><i class="bi bi-pencil-square"></i> Compose</a>
    <a href="group.php" class="npill active"><i class="bi bi-people"></i> Group Chat</a>
  </div>
  <a href="../dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- CHAT CONTAINER -->
<div class="chat-container">
  
  <!-- Groups Sidebar -->
  <div class="groups-sidebar">
    <div class="sidebar-header">
      <i class="bi bi-hash me-1"></i> Channels
    </div>
    <?php foreach ($groups as $g): ?>
      <div class="group-item <?= $group_id == $g['id'] ? 'active' : '' ?>" 
           onclick="window.location.href='?group=<?= $g['id'] ?>'">
        <div class="group-name">
          <i class="bi bi-hash"></i><?= htmlspecialchars($g['name']) ?>
          <?php if ($g['unread_count'] > 0): ?>
            <span class="unread-badge"><?= $g['unread_count'] ?></span>
          <?php endif; ?>
        </div>
        <div class="group-desc"><?= htmlspecialchars($g['description'] ?? 'Team communication') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  
  <!-- Chat Area -->
  <div class="chat-area">
    <?php if ($group): ?>
      <!-- Chat Header -->
      <div class="chat-header">
        <div>
          <div class="chat-header-title">
            <i class="bi bi-hash me-1"></i><?= htmlspecialchars($group['name']) ?>
          </div>
          <div class="chat-header-meta"><?= count($members) ?> members</div>
        </div>
        <button class="btn-members" data-bs-toggle="modal" data-bs-target="#membersModal">
          <i class="bi bi-people me-1"></i> Members
        </button>
      </div>
      
      <!-- Messages Area -->
      <div class="messages-area" id="messagesArea">
        <?php foreach ($messages as $msg): 
          $is_sent = ($msg['user_id'] == $user_id);
        ?>
          <div class="message-bubble <?= $is_sent ? 'sent' : 'received' ?>">
            <div class="bubble">
              <?php if (!$is_sent): ?>
                <div class="sender-name"><?= htmlspecialchars($msg['full_name']) ?></div>
              <?php endif; ?>
              <div class="message-text"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
              <div class="message-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      
      <!-- Message Input -->
      <div class="message-input-area">
        <form method="POST" class="message-form" id="messageForm">
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="group_id" value="<?= $group_id ?>">
          <textarea name="message" class="message-input" rows="1" placeholder="Type your message..." required></textarea>
          <button type="submit" class="send-btn">
            <i class="bi bi-send-fill text-white"></i>
          </button>
        </form>
      </div>
      
    <?php else: ?>
      <div class="empty-chat">
        <div>
          <i class="bi bi-chat-dots-fill"></i>
          <p>Select a group to start chatting</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Members Modal -->
<div class="modal fade" id="membersModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">Group Members</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php foreach ($members as $member): ?>
          <div class="member-item">
            <div class="member-avatar">
              <i class="bi bi-person-fill"></i>
            </div>
            <div>
              <div class="member-name"><?= htmlspecialchars($member['full_name']) ?></div>
            </div>
            <span class="member-role <?= $member['role'] ?>"><?= ucfirst($member['role']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-scroll to bottom
const messagesArea = document.getElementById('messagesArea');
if (messagesArea) {
  messagesArea.scrollTop = messagesArea.scrollHeight;
}

// Auto-refresh messages every 10 seconds
setInterval(function() {
  if (window.location.href.includes('group=')) {
    location.reload();
  }
}, 10000);

// Auto-resize textarea and submit on Ctrl+Enter
const messageInput = document.querySelector('.message-input');
if (messageInput) {
  messageInput.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'Enter') {
      document.getElementById('messageForm').submit();
    }
  });
}
</script>
</body>
</html>