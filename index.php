<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DICT | SARO Monitoring System</title>
    <link href="dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        html { scroll-behavior: smooth; }
        * { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        h1, h2, h3, h4, h5, h6, .brand-font { font-family: 'Outfit', sans-serif; }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f8fafc; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #64748b; }

        body { background: #ffffff; color: #1e293b; overflow-x: hidden; line-height: 1.6; }

        /* Animations */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .reveal { opacity: 0; transform: translateY(30px); transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1); }
        .reveal.active { opacity: 1; transform: translateY(0); }
        .delay-100 { transition-delay: 0.1s; }
        .delay-200 { transition-delay: 0.2s; }
        .delay-300 { transition-delay: 0.3s; }

        /* ── Navbar ── */
        .navbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 50;
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 5%;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .navbar.scrolled {
            background: rgba(255, 255, 255, 0.95);
            border-bottom-color: rgba(0,0,0,0.05);
            box-shadow: 0 4px 30px rgba(0,0,0,0.05);
            padding: 12px 5%;
        }
        .navbar.scrolled .brand-text { color: #0f172a; }
        .navbar.scrolled .nav-link { color: #475569; }
        .navbar.scrolled .nav-link:hover { color: #2563eb; }
        .navbar.scrolled .hamburger span { background: #0f172a; }
        
        .brand-text { color: #ffffff; font-weight: 800; font-size: 18px; letter-spacing: 0.5px; transition: color 0.3s; }
        .brand-sub { color: #3b82f6; font-weight: 700; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; }

        .nav-links { display: flex; align-items: center; gap: 40px; }
        .nav-link {
            font-size: 15px; font-weight: 500;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            position: relative;
            padding-bottom: 4px;
            transition: color 0.3s;
        }
        .nav-link::after {
            content: ''; position: absolute; left: 0; bottom: 0;
            width: 0%; height: 2px; background: #3b82f6;
            transition: width 0.3s ease; border-radius: 2px;
        }
        .nav-link:hover { color: #ffffff; }
        .nav-link:hover::after { width: 100%; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            padding: 14px 28px; font-size: 15px; font-weight: 600;
            border-radius: 12px; text-decoration: none; cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-primary {
            background: #2563eb; color: #ffffff;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
        }
        .btn-primary:hover {
            background: #1d4ed8; transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
        }
        .btn-outline {
            background: transparent; color: #ffffff;
            border: 1px solid rgba(255,255,255,0.4);
            backdrop-filter: blur(5px);
        }
        .btn-outline:hover {
            background: rgba(255,255,255,0.1); border-color: #ffffff; transform: translateY(-2px);
        }

        /* Hamburger */
        .hamburger {
            display: none; flex-direction: column; gap: 5px; cursor: pointer; background: none; border: none; z-index: 60;
        }
        .hamburger span {
            display: block; width: 24px; height: 2px; background: #ffffff; border-radius: 2px; transition: all 0.3s ease;
        }
        .hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger.open span:nth-child(2) { opacity: 0; }
        .hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Mobile Menu */
        .mobile-menu {
            position: fixed; inset: 0; z-index: 40;
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(20px);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 30px; opacity: 0; pointer-events: none; transition: opacity 0.4s ease;
        }
        .mobile-menu.open { opacity: 1; pointer-events: auto; }
        .mobile-menu .mob-link {
            font-size: 24px; font-weight: 700; color: #1e293b; text-decoration: none;
            transition: all 0.3s; transform: translateY(20px); opacity: 0; font-family: 'Outfit', sans-serif;
        }
        .mobile-menu.open .mob-link { transform: translateY(0); opacity: 1; }
        .mobile-menu .mob-link:hover { color: #2563eb; letter-spacing: 1px; }

        /* ── Hero ── */
        .hero-bg {
            position: relative; min-height: 100vh; display: flex; flex-direction: column;
            background: url('assets/dict_bg.jpg') center/cover no-repeat;
        }
        .hero-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(8,18,52,0.9) 0%, rgba(14,40,100,0.8) 100%);
        }
        .hero-content {
            position: relative; z-index: 10; flex: 1; display: flex; align-items: center;
            padding: 120px 5% 80px; max-width: 1400px; margin: 0 auto; width: 100%;
        }
        .hero-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; width: 100%;
        }
        .hero-badge {
            display: inline-flex; align-items: center; gap: 8px; padding: 6px 16px;
            background: rgba(37,99,235,0.2); color: #93c5fd; font-size: 12px; font-weight: 700;
            border-radius: 99px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 24px; border: 1px solid rgba(59,130,246,0.3);
            animation: fadeUp 0.8s forwards;
        }
        .hero-title {
            font-size: clamp(36px, 5vw, 64px); font-weight: 800; color: #ffffff;
            line-height: 1.1; margin-bottom: 24px; animation: fadeUp 0.8s forwards; animation-delay: 0.1s; opacity: 0;
        }
        .hero-subtitle {
            font-size: clamp(15px, 1.5vw, 18px); color: rgba(255,255,255,0.8);
            max-width: 540px; margin-bottom: 40px; font-weight: 400;
            animation: fadeUp 0.8s forwards; animation-delay: 0.2s; opacity: 0;
        }
        .hero-ctas {
            display: flex; gap: 16px; animation: fadeUp 0.8s forwards; animation-delay: 0.3s; opacity: 0; flex-wrap: wrap;
        }
        .trust-row {
            display: flex; align-items: center; gap: 24px; margin-top: 40px; padding-top: 30px; border-top: 1px solid rgba(255,255,255,0.1);
            animation: fadeUp 0.8s forwards; animation-delay: 0.4s; opacity: 0; flex-wrap: wrap;
        }
        .trust-item {
            display: flex; align-items: center; gap: 10px; font-size: 14px; color: rgba(255,255,255,0.6); font-weight: 500;
        }
        .hero-logo-wrap {
            display: flex; justify-content: center; align-items: center; animation: fadeUp 0.8s forwards; animation-delay: 0.3s; opacity: 0;
        }
        .hero-logo-inner {
            width: 100%; max-width: 440px; aspect-ratio: 1; background: #ffffff; border-radius: 50%;
            padding: 30px; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 30px 60px rgba(0,0,0,0.3), inset 0 0 0 8px rgba(37,99,235,0.1);
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        .hero-logo-inner img { width: 100%; height: 100%; object-fit: contain; }

        /* Scroll Bounce */
        @keyframes bounce { 0%, 100% { transform: translateY(0) translateX(-50%); } 50% { transform: translateY(-10px) translateX(-50%); } }
        .scroll-bounce { position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%); animation: bounce 2s infinite; z-index: 10; display: none; }
        @media (min-width: 768px) { .scroll-bounce { display: block; } }

        /* ── Services ── */
        .section { padding: 100px 5%; }
        .section-header { text-align: center; margin-bottom: 60px; }
        .section-badge {
            display: inline-block; font-size: 12px; font-weight: 700; color: #2563eb;
            text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px;
            background: rgba(37, 99, 235, 0.1); padding: 6px 16px; border-radius: 99px;
        }
        .section-title { font-size: clamp(28px, 4vw, 40px); font-weight: 800; color: #0f172a; margin-bottom: 16px; }

        .services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; max-width: 1200px; margin: 0 auto; }
        .service-card {
            background: #ffffff; border-radius: 20px; padding: 40px;
            border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            transition: all 0.4s ease; position: relative; overflow: hidden;
        }
        .service-card::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 4px;
            background: #2563eb; transform: scaleX(0); transition: transform 0.4s ease; transform-origin: left;
        }
        .service-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.08); border-color: transparent; }
        .service-card:hover::after { transform: scaleX(1); }
        .icon-circle {
            width: 64px; height: 64px; border-radius: 16px; background: #eff6ff; color: #2563eb;
            display: flex; align-items: center; justify-content: center; margin-bottom: 24px; transition: all 0.3s ease;
        }
        .service-card:hover .icon-circle { background: #2563eb; color: #ffffff; transform: scale(1.1) rotate(-5deg); }
        .service-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
        .service-desc { font-size: 15px; color: #64748b; line-height: 1.7; }

        /* ── About ── */
        .about-section { background: #f8fafc; }
        .about-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; max-width: 1200px; margin: 0 auto; align-items: center; }
        .about-logo-wrapper { display: flex; justify-content: center; }
        .about-logo-box {
            background: #ffffff; border-radius: 50%; padding: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0; max-width: 360px; width: 100%; aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
        }
        .about-logo-box img { width: 100%; height: 100%; object-fit: contain; }
        .about-content { max-width: 540px; }
        .about-text { font-size: 16px; color: #475569; line-height: 1.8; margin-bottom: 24px; }
        .feature-list { display: grid; gap: 16px; }
        .feature-item { display: flex; align-items: center; gap: 16px; padding: 16px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; transition: transform 0.3s; }
        .feature-item:hover { transform: translateX(8px); border-color: #93c5fd; }
        .feature-icon { color: #2563eb; background: #eff6ff; padding: 8px; border-radius: 50%; display: flex; }
        .feature-text { font-size: 15px; font-weight: 600; color: #1e293b; }

        /* ── Footer ── */
        .footer { background: #0f172a; padding: 60px 5% 30px; color: #94a3b8; }
        .footer-inner { max-width: 1200px; margin: 0 auto; text-align: center; }
        .footer-logo { width: 48px; height: 48px; margin: 0 auto 20px; opacity: 0.8; }
        .footer-text { font-size: 14px; margin-bottom: 8px; }

        /* ════ Responsive ════ */
        @media (max-width: 992px) {
            .hero-grid { grid-template-columns: 1fr; text-align: center; gap: 40px; }
            .hero-content { padding-top: 140px; }
            .hero-subtitle { margin: 0 auto 30px; }
            .hero-ctas, .trust-row { justify-content: center; }
            .hero-logo-wrap { order: -1; }
            .hero-logo-inner { max-width: 280px; padding: 20px; }
            .about-grid { grid-template-columns: 1fr; text-align: center; }
            .about-logo-box { max-width: 280px; padding: 20px; }
            .about-content { margin: 0 auto; }
            .feature-item { text-align: left; }
        }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .hamburger { display: flex; }
            .hero-title { font-size: 40px; }
            .btn { width: 100%; }
            .trust-row { flex-direction: column; gap: 12px; }
        }
    </style>
</head>
<body>

    <!-- ══ Mobile Menu ══ -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="#" class="mob-link" onclick="toggleMenu()">Home</a>
        <a href="#services" class="mob-link" onclick="toggleMenu()">Services</a>
        <a href="#about" class="mob-link" onclick="toggleMenu()">About</a>
        <a href="login.php" class="btn btn-primary" style="margin-top:20px; width:auto; padding: 14px 40px;" onclick="toggleMenu()">Sign In Portal</a>
    </div>

    <!-- ══ Navbar ══ -->
    <nav class="navbar" id="navbar">
        <div style="display:flex;align-items:center;gap:12px; z-index:60;">
            <div style="background:#ffffff; padding:6px; border-radius:50%; box-shadow:0 4px 10px rgba(0,0,0,0.1);">
                <img src="assets/dict_logo.png" alt="DICT Logo" style="width:36px;height:36px;object-fit:contain;">
            </div>
            <div>
                <p class="brand-text">DICT</p>
                <p class="brand-sub">Region IX &amp; BASULTA</p>
            </div>
        </div>

        <div class="nav-links">
            <a href="#" class="nav-link">Home</a>
            <a href="#services" class="nav-link">Services</a>
            <a href="#about" class="nav-link">About</a>
        </div>

        <div style="display:flex;align-items:center;gap:16px;">
            <a href="login.php" class="btn btn-primary" style="padding: 10px 24px; font-size:14px; box-shadow:none; z-index:60; display:none;">Sign In Portal</a>
            <button class="hamburger" id="hamburger" onclick="toggleMenu()" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
        <!-- Show Sign In button on desktop via inline style override -->
        <style> @media (min-width: 769px) { .btn-primary[href="login.php"] { display: inline-flex !important; } } </style>
    </nav>

    <!-- ══ Hero ══ -->
    <div class="hero-bg">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <div class="hero-grid">
                <div>
                    <div class="hero-badge">
                        <span style="width:6px;height:6px;background:#60a5fa;border-radius:50%;box-shadow:0 0 10px #60a5fa;"></span>
                        SARO Monitoring Portal
                    </div>
                    <h1 class="hero-title">Track Funds.<br><span style="color:#60a5fa;">Made Smarter.</span></h1>
                    <p class="hero-subtitle">
                        A modern system for monitoring Special Allotment Release Orders in <strong>DRRM DICT</strong>. Real-time visibility into budget utilization and procurement.
                    </p>
                    <div class="hero-ctas">
                        <a href="login.php" class="btn btn-primary">
                            Access Dashboard
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </a>
                        <a href="#services" class="btn btn-outline">Explore Features</a>
                    </div>
                    <div class="trust-row">
                        <div class="trust-item">
                            <svg width="20" height="20" fill="none" stroke="#60a5fa" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Real-time Tracking
                        </div>
                        <div class="trust-item">
                            <svg width="20" height="20" fill="none" stroke="#60a5fa" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Secure Access
                        </div>
                        <div class="trust-item">
                            <svg width="20" height="20" fill="none" stroke="#60a5fa" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Audit Ready
                        </div>
                    </div>
                </div>
                <div class="hero-logo-wrap">
                    <div class="hero-logo-inner">
                        <img src="assets/dict_logo.png" alt="DICT Logo">
                    </div>
                </div>
            </div>
        </div>
        <div class="scroll-bounce">
            <a href="#services" style="color:rgba(255,255,255,0.6);">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
            </a>
        </div>
    </div>

    <!-- ══ Services ══ -->
    <section id="services" class="section">
        <div class="section-header reveal">
            <div class="section-badge">Core Capabilities</div>
            <h2 class="section-title">Powerful Features</h2>
        </div>
        <div class="services-grid">
            <div class="service-card reveal">
                <div class="icon-circle">
                    <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
                <h3 class="service-title">Real-Time Dashboard</h3>
                <p class="service-desc">Instant visibility into SARO budget allocation, obligation rates, and fund utilization with interactive metrics.</p>
            </div>
            <div class="service-card reveal delay-100">
                <div class="icon-circle">
                    <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h3 class="service-title">Lifecycle Tracking</h3>
                <p class="service-desc">Monitor the full procurement lifecycle from SARO issuance through obligation and liquidation securely.</p>
            </div>
            <div class="service-card reveal delay-200">
                <div class="icon-circle">
                    <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h3 class="service-title">Export Records</h3>
                <p class="service-desc">Generate and download comprehensive SARO reports ready for central office submission and compliance audits.</p>
            </div>
        </div>
    </section>

    <!-- ══ About ══ -->
    <section id="about" class="section about-section">
        <div class="about-grid">
            <div class="about-logo-wrapper reveal">
                <div class="about-logo-box">
                    <img src="assets/dict_logo.png" alt="DICT Logo">
                </div>
            </div>
            <div class="about-content reveal delay-100">
                <div class="section-badge">About Us</div>
                <h2 class="section-title" style="text-align:left;">Empowering Transparent Governance</h2>
                <p class="about-text">
                    The Department of Information and Communications Technology (DICT) Region IX serves the Zamboanga Peninsula while BASULTA covers Basilan, Sulu, and Tawi-Tawi — working together to bring digital transformation to the communities we serve.
                </p>
                <div class="feature-list">
                    <div class="feature-item">
                        <div class="feature-icon"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
                        <span class="feature-text">Streamlined SARO Monitoring Processes</span>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
                        <span class="feature-text">Transparent Fund Utilization Reporting</span>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
                        <span class="feature-text">Secure Role-Based Access Control</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ Footer ══ -->
    <footer class="footer">
        <div class="footer-inner">
            <img src="assets/dict_logo.png" alt="DICT Logo" class="footer-logo">
            <p class="footer-text">&copy; 2026 Department of Information and Communications Technology</p>
            <p class="footer-text">Region IX &amp; BASULTA. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Navbar Scroll
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            navbar.classList.toggle('scrolled', window.scrollY > 50);
        });

        // Smooth Scroll
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth' });
                    if (document.getElementById('mobileMenu').classList.contains('open')) {
                        toggleMenu();
                    }
                }
            });
        });

        // Mobile Menu
        function toggleMenu() {
            const menu = document.getElementById('mobileMenu');
            const burger = document.getElementById('hamburger');
            const isOpen = menu.classList.toggle('open');
            burger.classList.toggle('open', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }

        // Reveal on Scroll
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });
        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
    </script>
</body>
</html>
