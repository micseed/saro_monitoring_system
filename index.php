<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DICT | SARO Monitoring System</title>
    <link href="dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        html { scroll-behavior: smooth; }
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #93c5fd; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #3b82f6; }

        /* ── Navbar ── */
        .navbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 50;
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 48px;
            background: rgba(0,0,0,0.28);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: all 0.3s ease;
        }
        .navbar.scrolled {
            background: #1e3a8a;
            border-bottom-color: rgba(255,255,255,0.05);
            box-shadow: 0 4px 24px rgba(0,0,0,0.25);
        }
        .nav-links { display: flex; align-items: center; gap: 36px; }
        .nav-link {
            font-size: 14px; font-weight: 500;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: color 0.2s;
        }
        .nav-link:hover { color: #93c5fd; }

        /* Hamburger */
        .hamburger {
            display: none;
            flex-direction: column; gap: 5px;
            cursor: pointer; background: none; border: none; padding: 4px;
        }
        .hamburger span {
            display: block; width: 24px; height: 2px;
            background: #fff; border-radius: 2px;
            transition: all 0.3s ease;
        }
        .hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger.open span:nth-child(2) { opacity: 0; }
        .hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Mobile menu drawer */
        .mobile-menu {
            display: none;
            position: fixed; inset: 0; z-index: 40;
            background: rgba(15,23,66,0.97);
            backdrop-filter: blur(16px);
            flex-direction: column; align-items: center; justify-content: center;
            gap: 32px;
        }
        .mobile-menu.open { display: flex; }
        .mobile-menu .mob-link {
            font-size: 22px; font-weight: 700;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            transition: color 0.2s;
        }
        .mobile-menu .mob-link:hover { color: #93c5fd; }

        /* ── Hero ── */
        .hero-bg {
            background-image: url('assets/dict_bg.jpg');
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .hero-overlay {
            background: linear-gradient(to right, rgba(8,18,52,0.93) 0%, rgba(14,40,100,0.72) 100%);
            position: absolute; inset: 0;
        }
        .hero-content {
            position: relative; z-index: 1;
            flex: 1; display: flex; align-items: center;
            padding: 130px 64px 80px;
            width: 100%;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 64px;
            align-items: center;
            width: 100%;
        }
        .hero-logo-wrap { display: flex; justify-content: center; align-items: center; }

        /* Hero CTA row */
        .hero-ctas {
            display: flex;
            flex-direction: row;
            gap: 14px;
            padding-top: 8px;
        }
        .hero-cta-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            padding: 14px 28px;
            background: #2563eb; color: #fff;
            font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
            border-radius: 10px; text-decoration: none;
            transition: background 0.25s ease;
        }
        .hero-cta-primary:hover { background: #1d4ed8; }
        .hero-cta-secondary {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            padding: 14px 24px;
            background: transparent; color: #fff;
            font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
            border-radius: 10px; border: 2px solid rgba(255,255,255,0.5);
            text-decoration: none; transition: all 0.25s ease;
        }
        .hero-cta-secondary:hover { border-color: #93c5fd; color: #93c5fd; }

        /* Trust badges */
        .trust-row {
            display: flex;
            align-items: center;
            gap: 28px;
            margin-top: 28px;
            flex-wrap: wrap;
        }
        .trust-item {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: rgba(255,255,255,0.55); font-weight: 500;
        }

        /* Scroll bounce — hidden on mobile */
        @keyframes bounce {
            0%, 100% { transform: translateY(0) translateX(-50%); }
            50% { transform: translateY(-10px) translateX(-50%); }
        }
        .scroll-bounce {
            position: absolute; bottom: 36px; left: 50%;
            transform: translateX(-50%);
            animation: bounce 2s ease-in-out infinite;
            z-index: 10;
        }

        /* ── Service cards ── */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }
        .service-card {
            background: #fff; border-radius: 12px; padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            border-top: 4px solid #3b82f6;
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }
        .service-card:hover { box-shadow: 0 12px 40px rgba(0,0,0,0.13); transform: translateY(-4px); }
        .icon-circle {
            width: 60px; height: 60px; border-radius: 50%;
            background: #dbeafe;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 20px;
            transition: background 0.3s ease;
        }
        .service-card:hover .icon-circle { background: #2563eb; }
        .service-card:hover .icon-circle svg { stroke: #fff; }

        /* ── About ── */
        .about-row { display: flex; flex-direction: row; align-items: center; gap: 80px; }
        .check-item { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
        .check-icon {
            background: #dbeafe; color: #2563eb;
            border-radius: 50%; padding: 4px;
            flex-shrink: 0; margin-top: 2px;
        }

        /* ══════════════════════════════════
           RESPONSIVE BREAKPOINTS
        ══════════════════════════════════ */

        /* Tablet */
        @media (max-width: 1023px) {
            .navbar { padding: 14px 28px; }
            .hero-content { padding: 110px 32px 70px; }
            .hero-grid { gap: 40px; }
            .hero-logo-wrap div { width: 300px !important; height: 300px !important; }
            .services-grid { grid-template-columns: repeat(2, 1fr); }
            .about-row { gap: 48px; }
        }

        /* Mobile */
        @media (max-width: 767px) {
            /* Navbar */
            .navbar { padding: 14px 20px; }
            .nav-links { display: none; }
            .hamburger { display: flex; }

            /* Hero */
            .hero-content { padding: 100px 24px 64px; align-items: flex-start; }
            .hero-grid { grid-template-columns: 1fr; gap: 0; }
            .hero-logo-wrap { display: none; }

            /* Hero text */
            .hero-title { font-size: 36px !important; line-height: 1.1 !important; margin-bottom: 16px !important; }
            .hero-subtitle { font-size: 14px !important; margin-bottom: 28px !important; }

            /* CTAs: stack vertically, full width */
            .hero-ctas { flex-direction: column; gap: 12px; }
            .hero-cta-primary,
            .hero-cta-secondary { width: 100%; padding: 13px 20px; font-size: 14px; }

            /* Trust row: wrap tightly */
            .trust-row { gap: 16px; margin-top: 20px; }
            .trust-item { font-size: 12px; }

            /* Hide scroll arrow on mobile */
            .scroll-bounce { display: none; }

            /* Services */
            .services-grid { grid-template-columns: 1fr; }
            #services { padding: 56px 24px !important; }

            /* About */
            .about-row { flex-direction: column; gap: 28px; }
            #about { padding: 56px 24px !important; }
            .about-logo { width: 180px !important; height: 180px !important; }
            .about-row > div:first-child { order: -1; }

            /* Footer */
            footer { padding: 24px 24px !important; font-size: 12px !important; }

            /* Signin button: icon only */
            .signin-label { display: none; }
        }

        /* Small phones */
        @media (max-width: 420px) {
            .hero-title { font-size: 30px !important; }
            .hero-content { padding: 95px 20px 56px; }
        }
    </style>
</head>
<body style="background:#fff; color:#1e293b; overflow-x:hidden;">

    <!-- ══ Mobile Menu Drawer ══ -->
    <div class="mobile-menu" id="mobileMenu">
        <button onclick="toggleMenu()" style="position:absolute;top:20px;right:20px;background:none;border:none;cursor:pointer;color:#fff;">
            <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        <a href="#" class="mob-link" onclick="toggleMenu()">Home</a>
        <a href="#services" class="mob-link" onclick="toggleMenu()">Services</a>
        <a href="#about" class="mob-link" onclick="toggleMenu()">About</a>
        <a href="login.php"
           style="display:inline-flex;align-items:center;gap:8px;padding:12px 32px;
                  background:#2563eb;color:#fff;font-size:15px;font-weight:700;
                  border-radius:10px;text-decoration:none;margin-top:8px;">
            Sign In
        </a>
    </div>

    <!-- ══ Navbar ══ -->
    <nav class="navbar" id="navbar">
        <!-- Logo + Brand -->
        <div style="display:flex;align-items:center;gap:12px;">
            <img src="assets/dict_logo.png" alt="DICT Logo"
                 style="width:44px;height:44px;border-radius:50%;border:2px solid #3b82f6;
                        object-fit:contain;background:#fff;padding:4px;">
            <div>
                <p style="font-size:15px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:0.06em;line-height:1.1;">DICT</p>
                <p style="font-size:9px;color:#93c5fd;font-weight:600;letter-spacing:0.16em;text-transform:uppercase;">Region IX &amp; BASULTA</p>
            </div>
        </div>

        <!-- Nav links -->
        <div class="nav-links">
            <a href="#" class="nav-link">Home</a>
            <a href="#services" class="nav-link">Services</a>
            <a href="#about" class="nav-link">About</a>
        </div>

        <!-- Right: Sign In + Hamburger -->
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="login.php"
               style="display:inline-flex;align-items:center;gap:8px;padding:9px 22px;
                      background:#2563eb;color:#fff;font-size:13px;font-weight:700;
                      font-family:'Poppins',sans-serif;border-radius:8px;text-decoration:none;
                      letter-spacing:0.03em;transition:background 0.25s ease;"
               onmouseover="this.style.background='#1d4ed8'"
               onmouseout="this.style.background='#2563eb'">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                </svg>
                <span class="signin-label">Sign In</span>
            </a>
            <button class="hamburger" id="hamburger" onclick="toggleMenu()" aria-label="Open menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </nav>

    <!-- ══ Hero ══ -->
    <div class="hero-bg">
        <div class="hero-overlay"></div>

        <div class="hero-content">
            <div class="hero-grid">
                <!-- Left: Text -->
                <div>
                    <!-- Badge -->
                    <div style="display:inline-block;background:#2563eb;color:#fff;
                                font-size:11px;font-weight:700;padding:5px 14px;
                                border-radius:4px;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:22px;">
                        SARO Monitoring Portal
                    </div>

                    <!-- Heading -->
                    <h1 class="hero-title"
                        style="font-size:clamp(36px,5.5vw,72px);font-weight:900;color:#fff;
                               line-height:1.08;letter-spacing:-0.03em;margin-bottom:20px;">
                        Track Funds.<br>
                        <span style="background:linear-gradient(90deg,#60a5fa,#bfdbfe);
                                     -webkit-background-clip:text;-webkit-text-fill-color:transparent;
                                     background-clip:text;">
                            Made Smarter.
                        </span>
                    </h1>

                    <!-- Subtitle -->
                    <p class="hero-subtitle"
                       style="font-size:clamp(14px,1.8vw,17px);color:rgba(255,255,255,0.7);
                              max-width:540px;line-height:1.8;margin-bottom:36px;font-weight:400;">
                        A system for monitoring Special Allotment Release Orders in
                        <strong style="color:#fff;font-weight:700;">DRRM DICT.</strong>
                        Real-time visibility into budget utilization and procurement status.
                    </p>

                    <!-- CTAs -->
                    <div class="hero-ctas">
                        <a href="#services" class="hero-cta-secondary">
                            Learn More
                        </a>
                    </div>
                </div>

                <!-- Right: Logo -->
                <div class="hero-logo-wrap">
                    <div style="width:420px;height:420px;background:#fff;border-radius:50%;
                                padding:10px;display:flex;align-items:center;justify-content:center;
                                box-shadow:0 24px 64px rgba(0,0,0,0.45),0 0 0 3px rgba(255,255,255,0.6);">
                        <img src="assets/dict_logo.png" alt="DICT Logo"
                             style="width:100%;height:100%;object-fit:contain;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Scroll arrow — hidden on mobile via CSS -->
        <div class="scroll-bounce">
            <a href="#services" style="color:rgba(255,255,255,0.55);text-decoration:none;display:block;"
               onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'">
                <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                </svg>
            </a>
        </div>
    </div>

    <!-- ══ Services ══ -->
    <section id="services" style="padding:90px 64px;background:#f8fafc;scroll-margin-top:68px;">
        <div style="max-width:1200px;margin:0 auto;">

            <div style="text-align:center;margin-bottom:64px;">
                <p style="font-size:11px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:0.16em;margin-bottom:8px;">What We Offer</p>
                <h2 style="font-size:clamp(26px,4vw,40px);font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:16px;">Our Services</h2>
                <div style="width:72px;height:4px;background:#3b82f6;border-radius:99px;margin:0 auto;"></div>
            </div>

            <div class="services-grid">

                <div class="service-card">
                    <div class="icon-circle">
                        <svg width="28" height="28" fill="none" stroke="#2563eb" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <h3 style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:12px;">Real-Time Dashboard</h3>
                    <p style="font-size:14px;color:#64748b;line-height:1.75;font-weight:400;">
                        Instant visibility into SARO budget allocation, obligation rates, and fund utilization.
                    </p>
                </div>

                <div class="service-card">
                    <div class="icon-circle">
                        <svg width="28" height="28" fill="none" stroke="#2563eb" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <h3 style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:12px;">Procurement Tracking</h3>
                    <p style="font-size:14px;color:#64748b;line-height:1.75;font-weight:400;">
                        Monitor the full procurement lifecycle from SARO issuance through obligation and liquidation with a complete audit trail.
                    </p>
                </div>

                <div class="service-card">
                    <div class="icon-circle">
                        <svg width="28" height="28" fill="none" stroke="#2563eb" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <h3 style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:12px;">Export Records</h3>
                    <p style="font-size:14px;color:#64748b;line-height:1.75;font-weight:400;">
                        Generate and download comprehensive SARO reports ready for central office submission and compliance audits.
                    </p>
                </div>

            </div>
        </div>
    </section>

    <!-- ══ About ══ -->
    <section id="about" style="padding:90px 64px;background:#fff;scroll-margin-top:68px;">
        <div style="max-width:1200px;margin:0 auto;">
            <div class="about-row">

                <!-- Logo -->
                <div style="flex:1;display:flex;justify-content:center;align-items:center;">
                    <img src="assets/dict_logo.png" alt="DICT Logo" class="about-logo"
                         style="width:280px;height:280px;object-fit:contain;">
                </div>

                <!-- Text -->
                <div style="flex:1;">
                    <p style="font-size:11px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:0.16em;margin-bottom:8px;">About Us</p>
                    <h2 style="font-size:clamp(24px,4vw,38px);font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:20px;line-height:1.15;">Empowering Transparent Governance</h2>
                    <p style="font-size:15px;color:#475569;line-height:1.85;margin-bottom:24px;font-weight:400;">
                        The Department of Information and Communications Technology (DICT) Region IX serves the Zamboanga Peninsula while BASULTA covers Basilan, Sulu, and Tawi-Tawi — working together to bring digital transformation to the communities we serve.
                    </p>
                    <p style="font-size:15px;color:#475569;line-height:1.85;margin-bottom:28px;font-weight:400;">
                        This SARO Monitoring System ensures full financial accountability, streamlined procurement oversight, and real-time reporting for central office compliance.
                    </p>
                    <div>
                        <div class="check-item">
                            <span class="check-icon">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <span style="font-size:14px;color:#334155;font-weight:600;">Streamlined SARO Monitoring Processes</span>
                        </div>
                        <div class="check-item">
                            <span class="check-icon">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <span style="font-size:14px;color:#334155;font-weight:600;">Transparent Fund Utilization Reporting</span>
                        </div>
                        <div class="check-item">
                            <span class="check-icon">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <span style="font-size:14px;color:#334155;font-weight:600;">Secure Role-Based Access Control</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ══ Footer ══ -->
    <footer style="background:#0f172a;padding:32px 64px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
        <p style="font-size:13px;color:#475569;font-weight:500;">
            &copy; 2026 Department of Information and Communications Technology &mdash; Region IX &amp; BASULTA. All rights reserved.
        </p>
    </footer>

    <script>
        // Navbar scroll
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            navbar.classList.toggle('scrolled', window.scrollY > 50);
        });

        // Smooth scroll for hash links
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', function(e) {
                const t = document.querySelector(this.getAttribute('href'));
                if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); }
            });
        });

        // Mobile menu toggle
        function toggleMenu() {
            const menu   = document.getElementById('mobileMenu');
            const burger = document.getElementById('hamburger');
            const isOpen = menu.classList.toggle('open');
            burger.classList.toggle('open', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }
    </script>
</body>
</html>
