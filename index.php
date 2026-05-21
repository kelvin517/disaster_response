<?php
require_once 'includes/config/config.php';

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
    <title><?php echo APP_NAME; ?> — Disaster Response Coordination</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,700;1,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --red: #E8271A;
            --red-dark: #B01F15;
            --red-glow: rgba(232, 39, 26, 0.15);
            --black: #0A0A0A;
            --off-black: #111111;
            --card-bg: #161616;
            --border: rgba(255,255,255,0.07);
            --border-hot: rgba(232, 39, 26, 0.4);
            --text: #F0EDE8;
            --muted: #7A7672;
            --heading-font: 'Bebas Neue', sans-serif;
            --body-font: 'DM Sans', sans-serif;
            --mono-font: 'DM Mono', monospace;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--body-font);
            background: var(--black);
            color: var(--text);
            overflow-x: hidden;
        }

        /* ─── SCROLLBAR ─── */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: var(--black); }
        ::-webkit-scrollbar-thumb { background: var(--red); border-radius: 2px; }

        /* ─── NOISE TEXTURE OVERLAY ─── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 9999;
            opacity: 0.4;
        }

        /* ─── NAV ─── */
        .nav {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 40px;
            background: rgba(10,10,10,0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            transition: padding 0.3s;
        }
        .nav.scrolled { padding: 12px 40px; }

        .nav-brand {
            font-family: var(--heading-font);
            font-size: 1.7rem;
            color: var(--red);
            letter-spacing: 0.05em;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nav-brand i { font-size: 1.3rem; }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
        }
        .nav-links a {
            color: var(--muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 8px 14px;
            border-radius: 6px;
            transition: color 0.2s, background 0.2s;
        }
        .nav-links a:hover, .nav-links a.active { color: var(--text); background: rgba(255,255,255,0.06); }
        .nav-links .btn-nav-reg {
            background: var(--red);
            color: white !important;
            border-radius: 6px;
            padding: 8px 18px;
        }
        .nav-links .btn-nav-reg:hover { background: var(--red-dark); }

        .nav-dropdown { position: relative; }
        .nav-dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            min-width: 180px;
            padding: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
        }
        .nav-dropdown:hover .nav-dropdown-menu { display: block; }
        .nav-dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            color: var(--muted) !important;
            text-transform: none;
            letter-spacing: 0;
        }
        .nav-dropdown-menu a:hover { color: var(--text) !important; }
        .nav-dropdown-menu hr { border-color: var(--border); margin: 6px 0; }

        .hamburger { display: none; background: none; border: none; color: var(--text); font-size: 1.4rem; cursor: pointer; }

        /* ─── HERO ─── */
        .hero {
            min-height: 100vh;
            display: grid;
            place-items: center;
            position: relative;
            padding: 120px 40px 80px;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 80% 60% at 50% 40%, rgba(232,39,26,0.12) 0%, transparent 70%),
                        radial-gradient(ellipse 50% 40% at 20% 80%, rgba(232,39,26,0.06) 0%, transparent 60%);
        }

        .hero-grid-lines {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 900px;
            width: 100%;
            text-align: center;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(232,39,26,0.1);
            border: 1px solid var(--border-hot);
            color: #FF6B5E;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            padding: 7px 18px;
            border-radius: 100px;
            margin-bottom: 36px;
            animation: fadeUp 0.6s ease both;
        }
        .hero-badge .dot {
            width: 6px; height: 6px;
            background: var(--red);
            border-radius: 50%;
            animation: blink 1.4s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.2; }
        }

        .hero-title {
            font-family: var(--heading-font);
            font-size: clamp(4rem, 10vw, 9rem);
            line-height: 0.92;
            letter-spacing: 0.01em;
            margin-bottom: 28px;
            animation: fadeUp 0.6s 0.1s ease both;
        }
        .hero-title .line-red { color: var(--red); }

        .hero-sub {
            font-size: 1.1rem;
            font-weight: 300;
            color: var(--muted);
            max-width: 560px;
            margin: 0 auto 48px;
            line-height: 1.75;
            animation: fadeUp 0.6s 0.2s ease both;
        }

        .hero-actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeUp 0.6s 0.3s ease both;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--red);
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            padding: 15px 32px;
            border-radius: 8px;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 0 0 0 rgba(232,39,26,0.5);
        }
        .btn-primary:hover {
            background: var(--red-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(232,39,26,0.35);
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: transparent;
            color: var(--text);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            padding: 15px 32px;
            border-radius: 8px;
            border: 1px solid var(--border);
            transition: border-color 0.2s, background 0.2s;
        }
        .btn-secondary:hover { border-color: rgba(255,255,255,0.2); background: rgba(255,255,255,0.04); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ─── STATS TICKER ─── */
        .stats-bar {
            background: var(--card-bg);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 24px 0;
            overflow: hidden;
        }
        .stats-track {
            display: flex;
            gap: 80px;
            width: max-content;
            animation: marquee 24s linear infinite;
        }
        @keyframes marquee {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 20px;
            white-space: nowrap;
        }
        .stat-num {
            font-family: var(--heading-font);
            font-size: 2.2rem;
            color: var(--red);
            letter-spacing: 0.04em;
        }
        .stat-label {
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .stat-sep { width: 1px; height: 36px; background: var(--border); }

        /* ─── SECTION HEADER ─── */
        .section-header {
            margin-bottom: 56px;
        }
        .section-tag {
            display: inline-block;
            font-family: var(--mono-font);
            font-size: 0.72rem;
            color: var(--red);
            letter-spacing: 0.2em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .section-title {
            font-family: var(--heading-font);
            font-size: clamp(2.4rem, 5vw, 4rem);
            letter-spacing: 0.02em;
            line-height: 1;
        }

        /* ─── SERVICES ─── */
        .services {
            padding: 100px 40px;
        }
        .services-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2px;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        .service-card {
            background: var(--card-bg);
            padding: 44px 40px;
            position: relative;
            transition: background 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .service-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(232,39,26,0.06) 0%, transparent 60%);
            opacity: 0;
            transition: opacity 0.3s;
        }
        .service-card:hover { background: #1C1C1C; }
        .service-card:hover::after { opacity: 1; }
        .service-card:hover .service-arrow { transform: translate(4px, -4px); color: var(--red); }

        .service-num {
            font-family: var(--mono-font);
            font-size: 0.7rem;
            color: var(--muted);
            letter-spacing: 0.15em;
            margin-bottom: 28px;
        }
        .service-icon {
            width: 52px; height: 52px;
            background: rgba(232,39,26,0.1);
            border: 1px solid var(--border-hot);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: var(--red);
            margin-bottom: 24px;
        }
        .service-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 12px;
            line-height: 1.3;
        }
        .service-desc {
            font-size: 0.88rem;
            color: var(--muted);
            line-height: 1.75;
        }
        .service-arrow {
            position: absolute;
            top: 40px; right: 40px;
            font-size: 1rem;
            color: var(--muted);
            transition: transform 0.25s, color 0.25s;
        }

        /* ─── INCIDENTS ─── */
        .incidents {
            padding: 100px 40px;
            background: var(--off-black);
        }
        .incidents-inner { max-width: 1200px; margin: 0 auto; }
        .incidents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }

        .incident-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            position: relative;
            transition: border-color 0.25s, transform 0.25s;
            overflow: hidden;
        }
        .incident-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--red);
            border-radius: 3px 0 0 3px;
        }
        .incident-card:hover { border-color: rgba(255,255,255,0.12); transform: translateY(-4px); }

        .incident-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .incident-type {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .incident-type i { color: var(--red); }

        .badge {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 100px;
        }
        .badge-critical, .badge-high { background: rgba(232,39,26,0.15); color: #FF6B5E; border: 1px solid rgba(232,39,26,0.3); }
        .badge-medium { background: rgba(220,140,0,0.12); color: #F0A030; border: 1px solid rgba(220,140,0,0.25); }
        .badge-low { background: rgba(50,180,90,0.1); color: #5DC87A; border: 1px solid rgba(50,180,90,0.2); }

        .incident-location {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .incident-desc {
            font-size: 0.88rem;
            color: rgba(240,237,232,0.7);
            line-height: 1.7;
            margin-bottom: 20px;
        }
        .incident-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        .incident-time { font-size: 0.75rem; color: var(--muted); display: flex; align-items: center; gap: 6px; }
        .badge-status {
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 100px;
            background: rgba(255,255,255,0.06);
            color: var(--muted);
            border: 1px solid var(--border);
        }

        .incidents-empty {
            text-align: center;
            padding: 60px;
            color: var(--muted);
            font-size: 1rem;
            grid-column: 1 / -1;
        }
        .incidents-empty i { font-size: 2.5rem; color: #3A5A40; margin-bottom: 16px; display: block; }

        .view-all-wrap { text-align: center; margin-top: 48px; }

        /* ─── ALERTS ─── */
        .alerts-section {
            padding: 100px 40px;
        }
        .alerts-inner { max-width: 1200px; margin: 0 auto; }
        .alerts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }

        .alert-card {
            border-radius: 12px;
            padding: 32px;
            background: var(--card-bg);
            border: 1px solid var(--border-hot);
            position: relative;
            overflow: hidden;
        }
        .alert-card::after {
            content: '';
            position: absolute;
            top: -50%; right: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(232,39,26,0.08) 0%, transparent 60%);
            pointer-events: none;
        }
        .alert-card.warning {
            border-color: rgba(220,140,0,0.3);
        }
        .alert-card.warning::after {
            background: radial-gradient(circle, rgba(220,140,0,0.06) 0%, transparent 60%);
        }

        .alert-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .alert-type-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #FF6B5E;
        }
        .alert-type-label i { font-size: 1rem; }
        .alert-type-label.warning-type { color: #F0A030; }
        .alert-icon-large { font-size: 1.6rem; color: rgba(232,39,26,0.4); }

        .alert-message { font-size: 0.9rem; color: rgba(240,237,232,0.8); line-height: 1.75; margin-bottom: 20px; }
        .alert-expiry {
            font-family: var(--mono-font);
            font-size: 0.72rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        /* ─── RESOURCES ─── */
        .resources-section {
            padding: 100px 40px;
            background: var(--off-black);
        }
        .resources-inner { max-width: 1200px; margin: 0 auto; }
        .resources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }

        .resource-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: border-color 0.2s, transform 0.2s;
        }
        .resource-card:hover { border-color: rgba(255,255,255,0.12); transform: translateY(-3px); }

        .resource-top { display: flex; align-items: center; justify-content: space-between; }
        .resource-icon-wrap {
            width: 44px; height: 44px;
            background: rgba(232,39,26,0.08);
            border: 1px solid var(--border-hot);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            color: var(--red);
        }
        .resource-count {
            font-family: var(--heading-font);
            font-size: 2.4rem;
            color: var(--red);
            letter-spacing: 0.04em;
            line-height: 1;
        }
        .resource-name {
            font-size: 1rem;
            font-weight: 600;
        }
        .resource-supply {
            font-size: 0.78rem;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        /* ─── CTA ─── */
        .cta-section {
            padding: 120px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .cta-section::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 600px; height: 300px;
            background: radial-gradient(ellipse, rgba(232,39,26,0.2) 0%, transparent 70%);
            pointer-events: none;
        }
        .cta-title {
            font-family: var(--heading-font);
            font-size: clamp(3rem, 7vw, 6.5rem);
            line-height: 0.95;
            letter-spacing: 0.02em;
            margin-bottom: 24px;
            position: relative;
        }
        .cta-title .outline-text {
            -webkit-text-stroke: 1px rgba(255,255,255,0.3);
            color: transparent;
        }
        .cta-sub { font-size: 1rem; color: var(--muted); max-width: 480px; margin: 0 auto 48px; line-height: 1.75; position: relative; }

        /* ─── FOOTER ─── */
        footer {
            background: #080808;
            border-top: 1px solid var(--border);
            padding: 80px 40px 40px;
        }
        .footer-inner {
            max-width: 1200px;
            margin: 0 auto;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 60px;
        }
        .footer-brand {
            font-family: var(--heading-font);
            font-size: 1.6rem;
            color: var(--red);
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        .footer-desc { font-size: 0.85rem; color: var(--muted); line-height: 1.85; }
        .footer-heading {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 20px;
        }
        .footer-links { list-style: none; display: flex; flex-direction: column; gap: 12px; }
        .footer-links a {
            color: rgba(240,237,232,0.55);
            text-decoration: none;
            font-size: 0.88rem;
            transition: color 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .footer-links a:hover { color: var(--text); }
        .footer-links a i { font-size: 0.7rem; color: var(--red); }

        .footer-contact { display: flex; flex-direction: column; gap: 14px; }
        .footer-contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            color: rgba(240,237,232,0.55);
        }
        .footer-contact-item i { color: var(--red); width: 16px; text-align: center; }

        .footer-social { display: flex; gap: 12px; margin-top: 24px; }
        .social-btn {
            width: 38px; height: 38px;
            border: 1px solid var(--border);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: var(--muted);
            text-decoration: none;
            font-size: 0.9rem;
            transition: border-color 0.2s, color 0.2s, background 0.2s;
        }
        .social-btn:hover { border-color: var(--red); color: var(--red); background: rgba(232,39,26,0.06); }

        .footer-bottom {
            padding-top: 32px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .footer-copy { font-size: 0.78rem; color: var(--muted); }
        .footer-copy span { color: var(--red); }

        /* ─── EMERGENCY FLOAT ─── */
        .emergency-float {
            position: fixed;
            bottom: 32px;
            right: 32px;
            z-index: 999;
        }
        .emergency-float a {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--red);
            color: white;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 14px 24px;
            border-radius: 100px;
            box-shadow: 0 0 0 0 rgba(232,39,26,0.6);
            animation: pulse-ring 2.5s ease infinite;
        }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(232,39,26,0.6); }
            70% { box-shadow: 0 0 0 16px rgba(232,39,26,0); }
            100% { box-shadow: 0 0 0 0 rgba(232,39,26,0); }
        }

        /* ─── REVEAL ANIMATIONS ─── */
        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.7s ease, transform 0.7s ease;
        }
        .reveal.visible { opacity: 1; transform: translateY(0); }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 900px) {
            .nav { padding: 16px 24px; }
            .nav-links { display: none; flex-direction: column; position: fixed; top: 70px; left: 0; right: 0; background: var(--card-bg); border-bottom: 1px solid var(--border); padding: 20px; gap: 4px; }
            .nav-links.open { display: flex; }
            .hamburger { display: block; }
            .hero { padding: 120px 24px 60px; }
            .services, .incidents, .alerts-section, .resources-section, .cta-section { padding: 70px 24px; }
            .footer-grid { grid-template-columns: 1fr; gap: 40px; }
            footer { padding: 60px 24px 32px; }
            .footer-bottom { flex-direction: column; gap: 12px; text-align: center; }
        }
    </style>
</head>
<body>

    <!-- ─── EMERGENCY FLOAT ─── -->
    <div class="emergency-float">
        <a href="modules/incidents/report.php">
            <i class="fas fa-exclamation-triangle"></i>
            Report Emergency
        </a>
    </div>

    <!-- ─── NAV ─── -->
    <nav class="nav" id="mainNav">
        <a href="index.php" class="nav-brand">
            <i class="fas fa-hands-helping"></i>
            <?php echo APP_NAME; ?>
        </a>
        <button class="hamburger" id="hamburger" aria-label="Menu"><i class="fas fa-bars"></i></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index.php" class="active">Home</a></li>
            <li><a href="modules/mapping/map.php">Live Map</a></li>
            <li><a href="modules/resources/resources.php">Resources</a></li>
            <li><a href="modules/alerts/alerts.php">Alerts</a></li>
            <?php if(isset($_SESSION['user_id'])): ?>
                <li class="nav-dropdown">
                    <a href="#" style="display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-user-circle"></i>
                        <?php echo $_SESSION['full_name']; ?>
                        <i class="fas fa-chevron-down" style="font-size:0.6rem;"></i>
                    </a>
                    <div class="nav-dropdown-menu">
                        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                        <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                        <a href="my_reports.php"><i class="fas fa-flag"></i> My Reports</a>
                        <hr>
                        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            <?php else: ?>
                <li><a href="modules/auth/login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                <li><a href="modules/auth/register.php" class="btn-nav-reg">Register</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- ─── HERO ─── -->
    <section class="hero">
        <div class="hero-bg"></div>
        <div class="hero-grid-lines"></div>
        <div class="hero-content">
            <div class="hero-badge">
                <span class="dot"></span>
                Live response platform — 24/7 active
            </div>
            <h1 class="hero-title">
                DISASTER<br>
                <span class="line-red">RESPONSE</span><br>
                COORDINATION
            </h1>
            <p class="hero-sub">Real-time platform connecting victims, volunteers, responders, and NGOs for faster, smarter disaster response across Kenya and beyond.</p>
            <div class="hero-actions">
                <?php if(!isset($_SESSION['user_id'])): ?>
                    <a href="modules/auth/register.php" class="btn-primary">
                        <i class="fas fa-hand-holding-heart"></i>
                        Join the Response Team
                    </a>
                    <a href="modules/mapping/map.php" class="btn-secondary">
                        <i class="fas fa-map"></i>
                        View Live Map
                    </a>
                <?php else: ?>
                    <a href="modules/incidents/report.php" class="btn-primary">
                        <i class="fas fa-exclamation-triangle"></i>
                        Report Emergency
                    </a>
                    <a href="modules/mapping/map.php" class="btn-secondary">
                        <i class="fas fa-map"></i>
                        View Live Map
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ─── STATS TICKER ─── -->
    <div class="stats-bar">
        <div class="stats-track">
            <!-- First set -->
            <div class="stat-item"><span class="stat-num">24/7</span><span class="stat-label">Emergency Response</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">500+</span><span class="stat-label">Registered Volunteers</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">50+</span><span class="stat-label">NGO Partners</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">1,000+</span><span class="stat-label">Lives Impacted</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">Kenya</span><span class="stat-label">Coverage Region</span></div>
            <div class="stat-sep"></div>
            <!-- Duplicate for seamless loop -->
            <div class="stat-item"><span class="stat-num">24/7</span><span class="stat-label">Emergency Response</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">500+</span><span class="stat-label">Registered Volunteers</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">50+</span><span class="stat-label">NGO Partners</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">1,000+</span><span class="stat-label">Lives Impacted</span></div>
            <div class="stat-sep"></div>
            <div class="stat-item"><span class="stat-num">Kenya</span><span class="stat-label">Coverage Region</span></div>
            <div class="stat-sep"></div>
        </div>
    </div>

    <!-- ─── SERVICES ─── -->
    <section class="services">
        <div style="max-width:1200px;margin:0 auto;">
            <div class="section-header reveal">
                <div class="section-tag">// What we do</div>
                <h2 class="section-title">OUR SERVICES</h2>
            </div>
            <div class="services-grid reveal">
                <a href="modules/incidents/report.php" class="service-card">
                    <div class="service-num">01 —</div>
                    <div class="service-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="service-name">Real-time Emergency Reporting</div>
                    <p class="service-desc">Report emergencies with GPS location, photos, and details. Get immediate response and real-time tracking from responders on the ground.</p>
                    <i class="fas fa-arrow-up-right service-arrow"></i>
                </a>
                <a href="modules/mapping/map.php" class="service-card">
                    <div class="service-num">02 —</div>
                    <div class="service-icon"><i class="fas fa-map"></i></div>
                    <div class="service-name">Live Interactive Maps</div>
                    <p class="service-desc">View danger zones, safe centers, evacuation routes, and resource distribution points in real-time with our dynamic mapping interface.</p>
                    <i class="fas fa-arrow-up-right service-arrow"></i>
                </a>
                <a href="modules/auth/register.php" class="service-card">
                    <div class="service-num">03 —</div>
                    <div class="service-icon"><i class="fas fa-hand-holding-heart"></i></div>
                    <div class="service-name">Volunteer Coordination</div>
                    <p class="service-desc">Register your skills, mark availability, and get matched with incidents needing your expertise. Every skill counts when lives are at stake.</p>
                    <i class="fas fa-arrow-up-right service-arrow"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- ─── ACTIVE INCIDENTS ─── -->
    <section class="incidents">
        <div class="incidents-inner">
            <div class="section-header reveal" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:20px;">
                <div>
                    <div class="section-tag">// Live data</div>
                    <h2 class="section-title">ACTIVE INCIDENTS</h2>
                </div>
                <a href="all_incidents.php" class="btn-secondary" style="font-size:0.82rem;padding:10px 22px;">
                    View all <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="incidents-grid reveal">
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
                        $sev = strtolower($row['severity']);
                        $badge_class = in_array($sev, ['critical','high']) ? 'badge-critical' : ($sev == 'medium' ? 'badge-medium' : 'badge-low');
                        echo '
                        <div class="incident-card">
                            <div class="incident-header">
                                <div class="incident-type">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    ' . ucfirst($row['incident_type']) . '
                                </div>
                                <span class="badge ' . $badge_class . '">' . strtoupper($row['severity']) . '</span>
                            </div>
                            <div class="incident-location">
                                <i class="fas fa-map-marker-alt"></i>
                                ' . htmlspecialchars($row['location_name']) . '
                            </div>
                            <p class="incident-desc">' . htmlspecialchars(substr($row['description'], 0, 110)) . '...</p>
                            <div class="incident-footer">
                                <span class="incident-time">
                                    <i class="fas fa-clock"></i>
                                    ' . date('M j, H:i', strtotime($row['reported_at'])) . '
                                </span>
                                <span class="badge-status">' . ucfirst($row['status']) . '</span>
                            </div>
                        </div>';
                    }
                } else {
                    echo '<div class="incidents-empty">
                            <i class="fas fa-check-circle"></i>
                            No active incidents at this moment. Stay safe.
                          </div>';
                }
                ?>
            </div>
        </div>
    </section>

    <!-- ─── ALERTS ─── -->
    <section class="alerts-section">
        <div class="alerts-inner">
            <div class="section-header reveal" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:20px;">
                <div>
                    <div class="section-tag">// Emergency broadcasts</div>
                    <h2 class="section-title">ACTIVE ALERTS</h2>
                </div>
                <a href="alerts.php" class="btn-secondary" style="font-size:0.82rem;padding:10px 22px;">
                    All alerts <i class="fas fa-bell"></i>
                </a>
            </div>
            <div class="alerts-grid reveal">
                <?php
                $query = "SELECT * FROM alerts WHERE expires_at >= NOW() ORDER BY created_at DESC LIMIT 3";
                $result = mysqli_query($conn, $query);

                if(mysqli_num_rows($result) > 0) {
                    while($row = mysqli_fetch_assoc($result)) {
                        $is_danger = in_array($row['alert_type'], ['danger', 'evacuation']);
                        $card_class = $is_danger ? '' : 'warning';
                        $label_class = $is_danger ? '' : 'warning-type';
                        echo '
                        <div class="alert-card ' . $card_class . '">
                            <div class="alert-header">
                                <div class="alert-type-label ' . $label_class . '">
                                    <i class="fas fa-bell"></i>
                                    ' . ucfirst($row['alert_type']) . ' Alert
                                </div>
                                <i class="fas fa-exclamation-circle alert-icon-large"></i>
                            </div>
                            <p class="alert-message">' . htmlspecialchars($row['message']) . '</p>
                            <div class="alert-expiry">
                                <i class="fas fa-calendar-check"></i>
                                Valid until: ' . date('M j, Y H:i', strtotime($row['expires_at'])) . '
                            </div>
                        </div>';
                    }
                } else {
                    echo '<div class="incidents-empty" style="grid-column:1/-1;">
                            <i class="fas fa-shield-alt" style="color:#2a4a3a;"></i>
                            No active alerts. Stay vigilant!
                          </div>';
                }
                ?>
            </div>
        </div>
    </section>

    <!-- ─── RESOURCES ─── -->
    <section class="resources-section">
        <div class="resources-inner">
            <div class="section-header reveal" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:20px;">
                <div>
                    <div class="section-tag">// Supply inventory</div>
                    <h2 class="section-title">AVAILABLE RESOURCES</h2>
                </div>
                <a href="resources.php" class="btn-secondary" style="font-size:0.82rem;padding:10px 22px;">
                    All resources <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="resources-grid reveal">
                <?php
                $icons = [
                    'food' => 'fa-utensils', 'water' => 'fa-tint', 'medicine' => 'fa-capsules',
                    'shelter' => 'fa-home', 'clothing' => 'fa-tshirt', 'blankets' => 'fa-bed',
                    'first_aid' => 'fa-medkit', 'transport' => 'fa-truck'
                ];
                $query = "SELECT resource_type, SUM(quantity) as total_quantity, COUNT(DISTINCT ngo_id) as ngo_count 
                          FROM resources WHERE status = 'available' GROUP BY resource_type LIMIT 6";
                $result = mysqli_query($conn, $query);

                if(mysqli_num_rows($result) > 0) {
                    while($row = mysqli_fetch_assoc($result)) {
                        $icon = isset($icons[$row['resource_type']]) ? $icons[$row['resource_type']] : 'fa-box';
                        echo '
                        <div class="resource-card">
                            <div class="resource-top">
                                <div class="resource-icon-wrap"><i class="fas ' . $icon . '"></i></div>
                                <div class="resource-count">' . number_format($row['total_quantity']) . '</div>
                            </div>
                            <div class="resource-name">' . ucfirst($row['resource_type']) . '</div>
                            <div class="resource-supply">
                                <i class="fas fa-building"></i>
                                ' . $row['ngo_count'] . ' NGOs supplying · units available
                            </div>
                        </div>';
                    }
                } else {
                    echo '<div class="incidents-empty" style="grid-column:1/-1;">
                            <i class="fas fa-boxes" style="color:#2a3a4a;"></i>
                            Resource inventory being updated. Check back soon!
                          </div>';
                }
                ?>
            </div>
        </div>
    </section>

    <!-- ─── CTA ─── -->
    <section class="cta-section">
        <div class="section-tag reveal" style="margin-bottom:20px;">// Join the mission</div>
        <h2 class="cta-title reveal">
            EVERY<br>
            <span class="outline-text">SECOND</span><br>
            COUNTS
        </h2>
        <p class="cta-sub reveal">Join our network of responders, volunteers, and organizations working together to save lives during disaster.</p>
        <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;" class="reveal">
            <?php if(!isset($_SESSION['user_id'])): ?>
                <a href="modules/auth/register.php" class="btn-primary">
                    <i class="fas fa-hands-helping"></i>
                    Become a Responder
                </a>
            <?php else: ?>
                <a href="modules/incidents/report.php" class="btn-primary">
                    <i class="fas fa-phone-alt"></i>
                    Report an Emergency
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- ─── FOOTER ─── -->
    <footer>
        <div class="footer-inner">
            <div class="footer-grid">
                <div>
                    <div class="footer-brand">
                        <i class="fas fa-hands-helping"></i>
                        <?php echo APP_NAME; ?>
                    </div>
                    <p class="footer-desc">Real-time coordination platform for effective disaster management, connecting victims, volunteers, responders, and relief organizations across Kenya.</p>
                    <div class="footer-social">
                        <a href="#" class="social-btn"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="social-btn"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-btn"><i class="fab fa-whatsapp"></i></a>
                    </div>
                </div>
                <div>
                    <div class="footer-heading">Navigation</div>
                    <ul class="footer-links">
                        <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                        <li><a href="modules/mapping/map.php"><i class="fas fa-chevron-right"></i> Live Map</a></li>
                        <li><a href="modules/auth/register.php"><i class="fas fa-chevron-right"></i> Volunteer</a></li>
                        <li><a href="modules/resources/resources.php"><i class="fas fa-chevron-right"></i> Resources</a></li>
                        <li><a href="modules/alerts/alerts.php"><i class="fas fa-chevron-right"></i> Alerts</a></li>
                        <li><a href="modules/incidents/report.php"><i class="fas fa-chevron-right"></i> Report Emergency</a></li>
                    </ul>
                </div>
                <div>
                    <div class="footer-heading">Emergency Contacts</div>
                    <div class="footer-contact">
                        <div class="footer-contact-item"><i class="fas fa-phone-alt"></i> National Disaster: 999</div>
                        <div class="footer-contact-item"><i class="fas fa-phone-alt"></i> Red Cross: +254 700 123 456</div>
                        <div class="footer-contact-item"><i class="fas fa-envelope"></i> emergency@disasterresponse.org</div>
                        <div class="footer-contact-item"><i class="fas fa-map-marker-alt"></i> Kabarak University, Kenya</div>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p class="footer-copy">&copy; 2026 <span><?php echo APP_NAME; ?></span>. All rights reserved.</p>
                <p class="footer-copy">Built for <span>lives that matter</span> — Kenya</p>
            </div>
        </div>
    </footer>

    <script>
        // ─── NAV SCROLL ───
        const nav = document.getElementById('mainNav');
        window.addEventListener('scroll', () => {
            nav.classList.toggle('scrolled', window.scrollY > 50);
        });

        // ─── HAMBURGER ───
        document.getElementById('hamburger').addEventListener('click', () => {
            document.getElementById('navLinks').classList.toggle('open');
        });

        // ─── REVEAL ON SCROLL ───
        const reveals = document.querySelectorAll('.reveal');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    setTimeout(() => entry.target.classList.add('visible'), i * 80);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });
        reveals.forEach(el => observer.observe(el));
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}
?>