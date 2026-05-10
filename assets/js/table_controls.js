(() => {
    "use strict";

    function parseRowLimit(selectEl) {
        if (!selectEl) return Number.POSITIVE_INFINITY;
        const raw = (selectEl.value || "").toString();
        const m = raw.match(/\d+/);
        if (!m) return Number.POSITIVE_INFINITY;
        const n = parseInt(m[0], 10);
        return Number.isFinite(n) && n > 0 ? n : Number.POSITIVE_INFINITY;
    }

    /** Preset tab labels used on admin password requests + activity logs */
    function rowMatchesPresetTab(rowText, tabLabel) {
        const t = tabLabel.toLowerCase().replace(/\s+/g, " ").trim();
        if (!t || t === "all") return true;

        if (t === "pending") return /\bpending\b/i.test(rowText);
        if (t === "approved") return /\bapproved\b/i.test(rowText);
        if (t === "rejected") return /\brejected\b/i.test(rowText);

        if (t === "login") return /\blogin\b|\blogout\b/i.test(rowText);

        if (t === "changes") {
            if (/\blogin\b|\blogout\b/i.test(rowText)) return false;
            return /\b(create|created|edit|edited|delete|deleted)\b/i.test(rowText);
        }

        if (t === "admin") {
            return /password|request|approve|reject|account|role|user\b|admin/i.test(rowText);
        }

        return true;
    }

    function initPanel(panel) {
        const table = panel.querySelector("table");
        const tbody = table ? table.querySelector("tbody") : null;
        if (!tbody) return;

        const allRows = Array.from(tbody.querySelectorAll(":scope > tr"));
        if (!allRows.length) return;

        const emptyRow = allRows.find((tr) => !!tr.querySelector("td[colspan]")) || null;
        const dataRows = allRows.filter((tr) => tr !== emptyRow);
        if (!dataRows.length && !emptyRow) return;

        const searchInput = panel.querySelector(".panel-header .search-input");
        const showRowsSelect = panel.querySelector(".panel-footer .show-rows-select");
        const roleFilterSelect = panel.querySelector(".panel-header select.role-filter-select");
        const filterButton = panel.querySelector(
            ".panel-header button.btn.btn-ghost.btn-sm:not(.filter-tab), .panel-header .tb-btn"
        );
        const filterTabs = panel.querySelectorAll(".filter-tabs .filter-tab");

        let activeTabLabel = "all";
        if (filterTabs.length) {
            const active = panel.querySelector(".filter-tabs .filter-tab.active");
            activeTabLabel = ((active || filterTabs[0]).textContent || "all").trim() || "all";
        }

        function apply() {
            const q = (searchInput ? searchInput.value : "").trim().toLowerCase();
            const limit = parseRowLimit(showRowsSelect);

            let roleNeedle = "";
            if (roleFilterSelect) {
                const opt = roleFilterSelect.options[roleFilterSelect.selectedIndex];
                roleNeedle = (opt ? opt.text : roleFilterSelect.value || "").trim().toLowerCase();
            }

            let shown = 0;
            let matched = 0;

            dataRows.forEach((row) => {
                const text = row.textContent.toLowerCase();

                const searchOk = !q || text.includes(q);

                let roleOk = true;
                if (roleFilterSelect && roleNeedle && roleNeedle !== "all roles") {
                    roleOk = text.includes(roleNeedle);
                }

                let tabOk = true;
                if (filterTabs.length) {
                    tabOk = rowMatchesPresetTab(text, activeTabLabel);
                }

                const isMatch = searchOk && roleOk && tabOk;
                if (isMatch) matched += 1;

                const shouldShow = isMatch && shown < limit;
                row.style.display = shouldShow ? "" : "none";
                if (shouldShow) shown += 1;
            });

            if (emptyRow) {
                emptyRow.style.display = matched === 0 ? "" : "none";
            }
        }

        if (searchInput) {
            searchInput.addEventListener("input", apply);
        }
        if (showRowsSelect) {
            showRowsSelect.addEventListener("change", apply);
        }
        if (roleFilterSelect) {
            roleFilterSelect.addEventListener("change", apply);
        }
        if (filterButton) {
            filterButton.addEventListener("click", () => {
                if (!searchInput) {
                    apply();
                    return;
                }
                if (searchInput.value.trim() !== "") {
                    searchInput.value = "";
                    apply();
                } else {
                    searchInput.focus();
                    apply();
                }
            });
        }

        filterTabs.forEach((tab) => {
            tab.addEventListener("click", () => {
                filterTabs.forEach((t) => t.classList.remove("active"));
                tab.classList.add("active");
                activeTabLabel = (tab.textContent || "all").trim() || "all";
                apply();
            });
        });

        apply();
    }

    function initAllTablePanels() {
        document.querySelectorAll(".table-panel, .panel").forEach((panel) => {
            if (!panel.querySelector("table")) return;
            initPanel(panel);
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAllTablePanels);
    } else {
        initAllTablePanels();
    }
})();
