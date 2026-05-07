-- ============================================================
--  DICT SARO Monitoring System
--  Revised Database Schema
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
--  1. user_role
--     Lookup table for user roles.
--     UNIQUE on role prevents duplicate role names.
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
--     Core account table.
--     created_by self-references user — NULL for Super Admin
--     (bootstrapped via setup.php).
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
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (roleId)     REFERENCES user_role(roleId),
    FOREIGN KEY (created_by) REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  3. password_requests
--     Users submit a request; admin approves or rejects.
--     resolved_by tracks which admin acted on the request.
-- ============================================================
CREATE TABLE password_requests (
    requestId    INT  PRIMARY KEY AUTO_INCREMENT,
    userId       INT  NOT NULL,
    reason       TEXT NOT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note   TEXT,
    resolved_by  INT,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at  DATETIME,

    FOREIGN KEY (userId)      REFERENCES user(userId)  ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES user(userId)  ON DELETE SET NULL
);


-- ============================================================
--  4. audit_logs
--     Tracks every meaningful action in the system.
--     affected_table + record_id pinpoints the exact row changed.
--     ip_address retained from UI display requirements.
--     userId SET NULL on delete so logs survive account deletion.
-- ============================================================
CREATE TABLE audit_logs (
    logId          INT PRIMARY KEY AUTO_INCREMENT,
    userId         INT,
    action         ENUM('login','logout','create','edit','delete','view','approve','reject') NOT NULL,
    details        TEXT,
    affected_table VARCHAR(50),
    record_id      INT,
    ip_address     VARCHAR(45),
    timestamp      DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (userId) REFERENCES user(userId) ON DELETE SET NULL
);


-- ============================================================
--  5. saro
--     Heart of the system. One SARO per record.
--     saroNo is UNIQUE — no duplicate SARO numbers allowed.
--     fiscal_year scopes the record to a budget cycle.
--     userId = the encoder/admin who created this entry.
-- ============================================================
CREATE TABLE saro (
    saroId       INT          PRIMARY KEY AUTO_INCREMENT,
    userId       INT          NOT NULL,
    saroNo       VARCHAR(50)  NOT NULL UNIQUE,
    saro_title   VARCHAR(150) NOT NULL,
    fiscal_year  YEAR         NOT NULL,
    total_budget DECIMAL(15,2) NOT NULL,
    status       ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (userId) REFERENCES user(userId)
);


-- ============================================================
--  6. object_code
--     Each SARO declares one or more object codes.
--     code  = the official expenditure classification (e.g. 5-02-01-010).
--     projected_cost = planned budget under this object code.
-- ============================================================
CREATE TABLE object_code (
    objectId       INT          PRIMARY KEY AUTO_INCREMENT,
    saroId         INT          NOT NULL,
    code           VARCHAR(50)  NOT NULL,
    projected_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,

    FOREIGN KEY (saroId) REFERENCES saro(saroId) ON DELETE CASCADE
);


-- ============================================================
--  7. expense_items   (optional)
--     APP line items planned under an object code.
--     Users may skip this table and go straight to procurement.
-- ============================================================
CREATE TABLE expense_items (
    itemId    INT          PRIMARY KEY AUTO_INCREMENT,
    objectId  INT          NOT NULL,
    item_name VARCHAR(150) NOT NULL,

    FOREIGN KEY (objectId) REFERENCES object_code(objectId) ON DELETE CASCADE
);


-- ============================================================
--  8. procurement
--     Actual procurement activity under an object code.
--     objectId replaces the original saroId FK — saro is
--     reachable via object_code, keeping the hierarchy intact.
--     obligated_amount = final committed amount (may differ
--     from unit_cost × quantity after negotiation).
--     period_start/end replace the unqueryable proc_period text.
-- ============================================================
CREATE TABLE procurement (
    procurementId    INT          PRIMARY KEY AUTO_INCREMENT,
    objectId         INT          NOT NULL,
    pro_act          VARCHAR(150),
    is_travelExpense BOOLEAN      NOT NULL DEFAULT FALSE,
    quantity         INT,
    unit             VARCHAR(50),
    unit_cost        DECIMAL(15,2),
    obligated_amount DECIMAL(15,2),
    period_start     DATE,
    period_end       DATE,
    proc_date        DATE,
    remarks          TEXT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (objectId) REFERENCES object_code(objectId) ON DELETE CASCADE
);


-- ============================================================
--  9. required_documents
--     Seeded lookup — documents differ for regular vs travel.
--     applies_to_regular / applies_to_travel drives automatic
--     document checklist generation in the app.
--     sort_order controls display sequence.
-- ============================================================
CREATE TABLE required_documents (
    documentId         INT          PRIMARY KEY AUTO_INCREMENT,
    document_name      VARCHAR(150) NOT NULL,
    applies_to_regular BOOLEAN      NOT NULL DEFAULT TRUE,
    applies_to_travel  BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order         INT          NOT NULL DEFAULT 0
);

INSERT INTO required_documents (document_name, applies_to_regular, applies_to_travel, sort_order) VALUES
    -- Regular procurement documents
    ('Purchase Request',          TRUE,  FALSE, 1),
    ('Quotation Sheet',           TRUE,  FALSE, 2),
    ("Mayor's Permit",            TRUE,  FALSE, 3),
    ('BIR 2303',                  TRUE,  FALSE, 4),
    ('Supplemental APP',          TRUE,  FALSE, 5),
    ('Notice of Award',           TRUE,  FALSE, 6),
    ('Notice to Proceed',         TRUE,  FALSE, 7),
    ('Inspection and Acceptance', TRUE,  FALSE, 8),
    -- Travel expense documents
    ('Travel Order',              FALSE, TRUE,  1),
    ('Itinerary',                 FALSE, TRUE,  2),
    ('Certificate of Travel',     FALSE, TRUE,  3),
    ('Reimbursement Report',      FALSE, TRUE,  4),
    ('CENRR',                     FALSE, TRUE,  5),
    ('Travel Report',             FALSE, TRUE,  6),
    ('Travel Summary',            FALSE, TRUE,  7);


-- ============================================================
--  10. procurement_status
--      Tracks per-document status for each procurement.
--      UNIQUE on (procurementId, documentId) prevents duplicate
--      status rows for the same document.
--      updated_by records which user last changed the status.
-- ============================================================
CREATE TABLE procurement_status (
    statusId      INT  PRIMARY KEY AUTO_INCREMENT,
    procurementId INT  NOT NULL,
    

    FOREIGN KEY (procurementId) REFERENCES procurement(procurementId)     ON DELETE CASCADE,
    FOREIGN KEY (documentId)    REFERENCES required_documents(documentId),
    FOREIGN KEY (updated_by)    REFERENCES user(userId)                   ON DELETE SET NULL
);


-- ============================================================
--  11. signatory_role
--      Ordered list of required signatures before a procurement
--      is considered fully obligated.
--      sign_order enforces the signing sequence in the app.
-- ============================================================
CREATE TABLE signatory_role (
    signId      INT          PRIMARY KEY AUTO_INCREMENT,
    sign_name   VARCHAR(100) NOT NULL,
    sign_order  INT          NOT NULL,
    is_required BOOLEAN      NOT NULL DEFAULT TRUE
);

INSERT INTO signatory_role (sign_name, sign_order, is_required) VALUES
    ('Budget Officer Signature',  1, TRUE),
    ('End User Signature',        2, TRUE),
    ('BAC Chair Signature',       3, TRUE),
    ('RD Signature',              4, TRUE),
    ('PO Creation',               5, TRUE),
    ('Finance Signature',         6, TRUE),
    ('Conforme Signature',        7, TRUE);


-- ============================================================
--  12. proc_approval
--      Records each signatory's action on a procurement.
--      amount_obligated moved to procurement.obligated_amount.
--      UNIQUE on (procurementId, signId) prevents a signatory
--      from approving the same procurement twice.
--      approved_by = the actual user who performed the action.
-- ============================================================
CREATE TABLE proc_approval (
    approvId      INT  PRIMARY KEY AUTO_INCREMENT,
    procurementId INT  NOT NULL,
    signId        INT  NOT NULL,
    status        ENUM('approved','rejected') NOT NULL,
    approved_by   INT,
    remarks       TEXT,
    approval_date DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_proc_sign (procurementId, signId),

    FOREIGN KEY (procurementId) REFERENCES procurement(procurementId)  ON DELETE CASCADE,
    FOREIGN KEY (signId)        REFERENCES signatory_role(signId),
    FOREIGN KEY (approved_by)   REFERENCES user(userId)                ON DELETE SET NULL
);


SET FOREIGN_KEY_CHECKS = 1;
