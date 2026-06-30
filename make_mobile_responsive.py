import os
import glob

def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Skip if already processed
    if 'mobile-menu-btn' in content and '/* Mobile Responsiveness */' in content:
        print(f"Skipping {filepath} (already processed)")
        return

    css_injection = """
        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                z-index: 50;
                height: 100%;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.open {
                transform: translateX(0);
                box-shadow: 4px 0 24px rgba(0,0,0,0.1);
            }
            .topbar {
                padding: 0 16px;
                height: auto;
                min-height: 64px;
                flex-wrap: wrap;
            }
            .topbar-right {
                margin-left: auto;
            }
            .content {
                padding: 16px;
            }
            .stat-grid {
                grid-template-columns: 1fr;
            }
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .table-panel {
                min-height: auto;
                overflow-x: auto;
            }
            .mobile-menu-btn {
                display: flex !important;
                margin-right: 12px;
                align-items: center;
                justify-content: center;
                background: none;
                border: none;
                cursor: pointer;
                color: #64748b;
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.4);
                z-index: 40;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            .overlay.show {
                display: block;
                opacity: 1;
            }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .overlay { display: none !important; }
        }
"""

    js_injection = """
<script>
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.querySelector('.mobile-menu-btn');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.overlay');

    if(btn && sidebar && overlay) {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.add('open');
            overlay.classList.add('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }
});
</script>
"""

    button_html = """<div class="breadcrumb">
                <button class="mobile-menu-btn" aria-label="Toggle Menu">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>"""

    original_content = content

    # Inject CSS
    if '</style>' in content and '/* Mobile Responsiveness */' not in content:
        content = content.replace('</style>', css_injection + '    </style>', 1)

    # Inject Button
    if '<div class="breadcrumb">' in content and 'mobile-menu-btn' not in content:
        content = content.replace('<div class="breadcrumb">', button_html, 1)

    # Inject Overlay
    if '<main class="main">' in content and '<div class="overlay"></div>' not in content:
        content = content.replace('<main class="main">', '    <div class="overlay"></div>\n    <main class="main">', 1)

    # Inject JS
    if '</body>' in content and "document.querySelector('.mobile-menu-btn')" not in content:
        content = content.replace('</body>', js_injection + '</body>', 1)

    if content != original_content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Processed {filepath}")
    else:
        print(f"No changes needed for {filepath}")

php_files = glob.glob('saro/*.php') + glob.glob('admin/*.php')
for f in php_files:
    process_file(f)
