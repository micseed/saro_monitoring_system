-- ============================================================
--  DICT SARO Monitoring System
--  Rewritten Database Schema — 3NF
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
--  1. user_role
-- ============================================================
CREATE TABLE user_role (
    roleId INT         PRIMARY KEY AUTO_INCREMENT,
    role   VARCHAR(50) NOT NULL UNIQUE
);

INSERT INTO user_role (role) VALUES
    ('Super Admin'),
    ('Admin');


-- ============================================================
--  2. user
-- ============================================================
CREATE TABLE user (
    userId       INT          PRIMARY KEY AUTO_INCREMENT,
    roleId       INT          NOT NULL,
    last_name    VARCHAR(50)  NOT NULL,
    first_name   VARCHAR(50)  NOT NULL,
    middle_name  VARCHAR(50),
    phone_number VARCHAR(20),
    username     VARCHAR(50)  NOT NULL UNIQUE,
    email        VARCHAR(100) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login   DATETIME,
    created_by   INT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (roleId)     REFERENCES user_role(roleId),
    FOREIGN KEY (created_by) REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  3. password_requests
--     requested_new_password stores the plaintext password the
--     user wants to switch to; cleared after applied_at is set.
-- ============================================================
CREATE TABLE password_requests (
    requestId              INT      PRIMARY KEY AUTO_INCREMENT,
    userId                 INT      NOT NULL,
    reason                 TEXT     NOT NULL,
    status                 ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note             TEXT,
    requested_new_password TEXT,
    resolved_by            INT,
    requested_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at            DATETIME,
    applied_at             DATETIME,

    FOREIGN KEY (userId)      REFERENCES user(userId) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  4. audit_logs
-- ============================================================
CREATE TABLE audit_logs (
    logId          INT          PRIMARY KEY AUTO_INCREMENT,
    userId         INT,
    action         ENUM('login','logout','create','edit','delete','view','cancelled','obligated','lapsed') NOT NULL,
    details        TEXT,
    affected_table VARCHAR(50),
    record_id      INT,
    ip_address     VARCHAR(45),
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (userId) REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  5. saro
--     userId is nullable so deleting a user does not cascade-
--     delete their SARO records — the record is preserved with
--     userId set to NULL.
-- ============================================================
CREATE TABLE saro (
    saroId         INT           PRIMARY KEY AUTO_INCREMENT,
    userId         INT,
    saroNo         VARCHAR(50)   NOT NULL UNIQUE,
    saro_title     VARCHAR(150)  NOT NULL,
    fiscal_year    YEAR          NOT NULL,
    total_budget   DECIMAL(15,2) NOT NULL,
    date_released  DATE,
    valid_until    DATE,
    status         ENUM('active','cancelled','obligated','lapsed','deleted') NOT NULL DEFAULT 'active',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (userId) REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  6. object_code
-- ============================================================
CREATE TABLE object_code (
    objectId         INT           PRIMARY KEY AUTO_INCREMENT,
    saroId           INT           NOT NULL,
    code             VARCHAR(50)   NOT NULL,
    projected_cost   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_travelExpense BOOLEAN       NOT NULL DEFAULT FALSE,

    FOREIGN KEY (saroId) REFERENCES saro(saroId) ON DELETE CASCADE
);


-- ============================================================
--  7. expense_items
--     Optional APP line items planned under an object code.
-- ============================================================
CREATE TABLE expense_items (
    itemId    INT          PRIMARY KEY AUTO_INCREMENT,
    objectId  INT          NOT NULL,
    item_name VARCHAR(150) NOT NULL,

    FOREIGN KEY (objectId) REFERENCES object_code(objectId) ON DELETE CASCADE
);


-- ============================================================
--  8. procurement
--     userId is nullable so deleting a user does not cascade-
--     delete procurement records — preserved with userId NULL.
--     status covers the full procurement lifecycle.
-- ============================================================
CREATE TABLE procurement (
    procurementId    INT           PRIMARY KEY AUTO_INCREMENT,
    objectId         INT           NOT NULL,
    userId           INT,
    pro_act          VARCHAR(150),
    is_travelExpense BOOLEAN       NOT NULL DEFAULT FALSE,
    quantity         INT,
    unit             VARCHAR(50),
    unit_cost        DECIMAL(15,2),
    obligated_amount DECIMAL(15,2),
    period_start     DATE,
    period_end       DATE,
    proc_date        DATE,
    remarks          TEXT,
    status           ENUM('on_process','obligated','cancelled') NOT NULL DEFAULT 'on_process',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (objectId) REFERENCES object_code(objectId) ON DELETE CASCADE,
    FOREIGN KEY (userId)   REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  9. required_documents
--     Seeded lookup — file-based checklist items.
--     applies_to_regular / applies_to_travel determines which
--     documents apply based on procurement type.
-- ============================================================
CREATE TABLE required_documents (
    documentId         INT          PRIMARY KEY AUTO_INCREMENT,
    document_name      VARCHAR(150) NOT NULL,
    applies_to_regular BOOLEAN      NOT NULL DEFAULT TRUE,
    applies_to_travel  BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order         INT          NOT NULL DEFAULT 0
);

INSERT INTO required_documents (document_name, applies_to_regular, applies_to_travel, sort_order) VALUES
    ('Purchase Request',          TRUE,  FALSE, 1),
    ('Quotation Sheet',           TRUE,  FALSE, 2),
    ("Mayor's Permit",            TRUE,  FALSE, 3),
    ('BIR 2303',                  TRUE,  FALSE, 4),
    ('Supplemental APP',          TRUE,  FALSE, 5),
    ('Notice of Award',           TRUE,  FALSE, 6),
    ('Notice to Proceed',         TRUE,  FALSE, 7),
    ('Inspection and Acceptance', TRUE,  FALSE, 8),
    ('Travel Order',              FALSE, TRUE,  1),
    ('Itinerary',                 FALSE, TRUE,  2),
    ('Certificate of Travel',     FALSE, TRUE,  3),
    ('Reimbursement Report',      FALSE, TRUE,  4),
    ('CENRR',                     FALSE, TRUE,  5),
    ('Travel Report',             FALSE, TRUE,  6),
    ('Travel Summary',            FALSE, TRUE,  7);


-- ============================================================
--  10. proc_documents
--      Per-document (file) checklist status per procurement.
--      UNIQUE on (procurementId, documentId) prevents
--      duplicate rows for the same document.
--      updated_by + updated_at provide a full audit trail.
-- ============================================================
CREATE TABLE proc_documents (
    procDocId     INT  PRIMARY KEY AUTO_INCREMENT,
    procurementId INT  NOT NULL,
    documentId    INT  NOT NULL,
    status        ENUM('pending','waived') NOT NULL DEFAULT 'pending',
    updated_by    INT,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_proc_doc (procurementId, documentId),

    FOREIGN KEY (procurementId) REFERENCES procurement(procurementId) ON DELETE CASCADE,
    FOREIGN KEY (documentId)    REFERENCES required_documents(documentId),
    FOREIGN KEY (updated_by)    REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  11. signatory_role
--      Seeded lookup — ordered list of required signatures.
--      applies_to_regular / applies_to_travel mirrors the
--      same pattern as required_documents so the app can
--      auto-generate the correct signature checklist.
--      sign_order enforces display sequence in the UI.
-- ============================================================
CREATE TABLE signatory_role (
    signId             INT          PRIMARY KEY AUTO_INCREMENT,
    sign_name          VARCHAR(100) NOT NULL,
    sign_order         INT          NOT NULL,
    applies_to_regular BOOLEAN      NOT NULL DEFAULT TRUE,
    applies_to_travel  BOOLEAN      NOT NULL DEFAULT FALSE,
    is_required        BOOLEAN      NOT NULL DEFAULT TRUE
);

INSERT INTO signatory_role (sign_name, sign_order, applies_to_regular, applies_to_travel, is_required) VALUES
    ('Budget Officer Signature', 1, TRUE,  FALSE, TRUE),
    ('End User Signature',       2, TRUE,  FALSE, TRUE),
    ('BAC Chair Signature',      3, TRUE,  FALSE, TRUE),
    ('RD Signature',             4, TRUE,  TRUE,  TRUE),
    ('PO Creation',              5, TRUE,  FALSE, TRUE),
    ('Finance Signature',        6, TRUE,  TRUE,  TRUE),
    ('Conforme Signature',       7, TRUE,  TRUE,  TRUE);


-- ============================================================
--  12. proc_signatures
--      Per-signature checklist status per procurement.
--      Mirrors proc_documents exactly — same status ENUM,
--      same audit trail pattern, same UNIQUE constraint.
--      UNIQUE on (procurementId, signId) prevents duplicate
--      rows for the same signatory role.
-- ============================================================
CREATE TABLE proc_signatures (
    procSignId    INT  PRIMARY KEY AUTO_INCREMENT,
    procurementId INT  NOT NULL,
    signId        INT  NOT NULL,
    status        ENUM('pending','waived') NOT NULL DEFAULT 'pending',
    updated_by    INT,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_proc_sign (procurementId, signId),

    FOREIGN KEY (procurementId) REFERENCES procurement(procurementId) ON DELETE CASCADE,
    FOREIGN KEY (signId)        REFERENCES signatory_role(signId),
    FOREIGN KEY (updated_by)    REFERENCES user(userId) ON DELETE SET NULL
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  13. notification_state
--      Tracks per-user read/hidden state for each audit_log
--      notification entry.  A missing row means unread+visible.
-- ============================================================
CREATE TABLE IF NOT EXISTS notification_state (
    id        INT     PRIMARY KEY AUTO_INCREMENT,
    userId    INT     NOT NULL,
    logId     INT     NOT NULL,
    is_read   TINYINT NOT NULL DEFAULT 0,
    is_hidden TINYINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_user_log (userId, logId),
    FOREIGN KEY (userId) REFERENCES user(userId) ON DELETE CASCADE,
    FOREIGN KEY (logId)  REFERENCES audit_logs(logId) ON DELETE CASCADE
);
