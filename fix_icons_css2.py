import re

def add_css(filename):
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()

    css_to_add = """
.action-btn-del { color: #94a3b8; }
.action-btn-del:hover { background: #fee2e2; border-color: #fecaca; color: #dc2626; }
"""
    if '.action-btn-del { color:' not in content:
        # replace the first </style> which is in the head
        content = content.replace('</style>', f'{css_to_add}</style>', 1)
        with open(filename, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Added CSS to {filename}")

add_css('saro/lapsed_saro.php')

def add_restore_css(filename):
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()

    css_to_add = """
.action-btn-restore { color: #64748b; }
.action-btn-restore:hover { background: #dcfce7; border-color: #bbf7d0; color: #16a34a; }
"""
    if '.action-btn-restore' not in content:
        # replace the first </style> which is in the head
        content = content.replace('</style>', f'{css_to_add}</style>', 1)
        with open(filename, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Added restore CSS to {filename}")

add_restore_css('saro/cancelled_saro.php')
add_restore_css('saro/lapsed_saro.php')

