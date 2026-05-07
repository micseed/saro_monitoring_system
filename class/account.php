<?php

class Account {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ══════════════════════════════════════════════════════
    //  LOGIN
    // ══════════════════════════════════════════════════════

    public function login(string $identifier, string $password): array {
        if (empty($identifier) || empty($password)) {
            return $this->fail('Please enter both username and password.');
        }

        $user = $this->findByIdentifier($identifier);

        if (!$user) {
            return $this->fail('Invalid username or password.');
        }

        if (!password_verify($password, $user['password'])) {
            return $this->fail('Invalid username or password.');
        }

        if ($user['status'] === 'inactive') {
            return $this->fail('Your account has been deactivated. Please contact the administrator.');
        }

        $this->startSession($user);
        $this->updateLastLogin($user['userId']);
        $this->logAction($user['userId'], 'login', 'Successful login', 'user', $user['userId']);

        return ['success' => true, 'message' => 'Login successful.'];
    }

    // ══════════════════════════════════════════════════════
    //  LOGOUT
    // ══════════════════════════════════════════════════════

    public function logout(): void {
        $userId = $_SESSION['userId'] ?? null;

        if ($userId) {
            $this->logAction($userId, 'logout', 'User logged out', 'user', $userId);
        }

        session_unset();
        session_destroy();
    }

    // ══════════════════════════════════════════════════════
    //  CREATE ACCOUNT
    // ══════════════════════════════════════════════════════

    public function createAccount(array $data): array {
        $validation = $this->validateAccountData($data);
        if (!$validation['valid']) {
            return $this->fail($validation['message']);
        }

        if ($this->usernameExists($data['username'])) {
            return $this->fail('Username is already taken.');
        }

        if ($this->emailExists($data['email'])) {
            return $this->fail('Email address is already registered.');
        }

        $hashed = password_hash($data['password'], PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare("
            INSERT INTO user
                (roleId, last_name, first_name, middle_name, phone_number,
                 username, email, password, status, created_by)
            VALUES
                (:roleId, :last_name, :first_name, :middle_name, :phone_number,
                 :username, :email, :password, 'active', :created_by)
        ");

        $stmt->execute([
            ':roleId'       => $data['roleId'],
            ':last_name'    => $data['last_name'],
            ':first_name'   => $data['first_name'],
            ':middle_name'  => $data['middle_name']  ?? null,
            ':phone_number' => $data['phone_number'] ?? null,
            ':username'     => $data['username'],
            ':email'        => $data['email'],
            ':password'     => $hashed,
            ':created_by'   => $data['created_by']   ?? null,
        ]);

        $newUserId = (int) $this->pdo->lastInsertId();
        $fullName  = trim($data['first_name'] . ' ' . $data['last_name']);

        $this->logAction(
            $data['created_by'] ?? null,
            'create',
            "Created account for {$fullName}",
            'user',
            $newUserId
        );

        return ['success' => true, 'message' => 'Account created successfully.', 'userId' => $newUserId];
    }

    // ══════════════════════════════════════════════════════
    //  UPDATE ACCOUNT
    // ══════════════════════════════════════════════════════

    public function updateAccount(int $userId, array $data, int $updatedBy): array {
        $user = $this->findById($userId);

        if (!$user) {
            return $this->fail('User not found.');
        }

        if (!empty($data['username']) && $data['username'] !== $user['username']) {
            if ($this->usernameExists($data['username'])) {
                return $this->fail('Username is already taken.');
            }
        }

        if (!empty($data['email']) && $data['email'] !== $user['email']) {
            if ($this->emailExists($data['email'])) {
                return $this->fail('Email address is already registered.');
            }
        }

        $fields = [];
        $params = [':userId' => $userId];

        $editable = ['first_name', 'last_name', 'middle_name', 'phone_number', 'username', 'email', 'roleId', 'status'];

        foreach ($editable as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $pwValidation = $this->validatePassword($data['password']);
            if (!$pwValidation['valid']) {
                return $this->fail($pwValidation['message']);
            }
            $fields[]            = 'password = :password';
            $params[':password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) {
            return $this->fail('No fields provided to update.');
        }

        $sql = 'UPDATE user SET ' . implode(', ', $fields) . ' WHERE userId = :userId';
        $this->pdo->prepare($sql)->execute($params);

        $this->logAction($updatedBy, 'edit', "Updated account for userId {$userId}", 'user', $userId);

        return ['success' => true, 'message' => 'Account updated successfully.'];
    }

    // ══════════════════════════════════════════════════════
    //  DEACTIVATE / ACTIVATE ACCOUNT
    // ══════════════════════════════════════════════════════

    public function deactivateAccount(int $userId, int $deletedBy): array {
        if ($userId === $deletedBy) {
            return $this->fail('You cannot deactivate your own account.');
        }

        $user = $this->findById($userId);

        if (!$user) {
            return $this->fail('User not found.');
        }

        if ($user['status'] === 'inactive') {
            return $this->fail('Account is already inactive.');
        }

        $this->pdo->prepare("UPDATE user SET status = 'inactive' WHERE userId = ?")
                  ->execute([$userId]);

        $this->logAction($deletedBy, 'delete', "Deactivated account for userId {$userId}", 'user', $userId);

        return ['success' => true, 'message' => 'Account deactivated successfully.'];
    }

    public function activateAccount(int $userId, int $activatedBy): array {
        $user = $this->findById($userId);

        if (!$user) {
            return $this->fail('User not found.');
        }

        if ($user['status'] === 'active') {
            return $this->fail('Account is already active.');
        }

        $this->pdo->prepare("UPDATE user SET status = 'active' WHERE userId = ?")
                  ->execute([$userId]);

        $this->logAction($activatedBy, 'edit', "Reactivated account for userId {$userId}", 'user', $userId);

        return ['success' => true, 'message' => 'Account reactivated successfully.'];
    }

    // ══════════════════════════════════════════════════════
    //  DATA RETRIEVAL (LOGS & USERS)
    // ══════════════════════════════════════════════════════

    /**
     * Fetch recent audit logs with the associated user details.
     */
    public function getAuditLogs(int $limit = 100): array {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.first_name, u.last_name 
            FROM audit_logs a
            LEFT JOIN user u ON a.userId = u.userId
            ORDER BY a.timestamp DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch all users along with their roles and their creator.[cite: 3]
     */
    public function getAllUsers(): array {
        $stmt = $this->pdo->query("
            SELECT u.userId, u.first_name, u.last_name, u.email, u.status, u.last_login, ur.role,
                c.first_name as creator_fname, c.last_name as creator_lname
            FROM user u
            JOIN user_role ur ON u.roleId = ur.roleId
            LEFT JOIN user c ON u.created_by = c.userId
            ORDER BY u.first_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total number of audit logs.
     */
    public function countTotalLogs(): int {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM audit_logs");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch only Admin-role users (excludes Super Admin) for the SARO audit log view.
     */
    public function getAdminOnlyUsers(): array {
        $stmt = $this->pdo->query("
            SELECT u.userId, u.first_name, u.last_name, u.email, u.status, u.last_login, ur.role
            FROM user u
            JOIN user_role ur ON u.roleId = ur.roleId
            WHERE ur.role = 'Admin'
            ORDER BY u.first_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch audit logs only from Admin-role users (excludes Super Admin actions).
     */
    public function getAdminAuditLogs(int $limit = 100): array {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.first_name, u.last_name
            FROM audit_logs a
            INNER JOIN user u ON a.userId = u.userId
            INNER JOIN user_role ur ON ur.roleId = u.roleId
            WHERE ur.role = 'Admin'
            ORDER BY a.timestamp DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count audit logs only from Admin-role users.
     */
    public function countAdminLogs(): int {
        $stmt = $this->pdo->query("
            SELECT COUNT(*)
            FROM audit_logs a
            INNER JOIN user u ON a.userId = u.userId
            INNER JOIN user_role ur ON ur.roleId = u.roleId
            WHERE ur.role = 'Admin'
        ");
        return (int) $stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════════
    //  SESSION HELPERS
    // ══════════════════════════════════════════════════════

    public function isLoggedIn(): bool {
        return !empty($_SESSION['userId']);
    }

    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) {
            return null;
        }
        $user = $this->findById((int) $_SESSION['userId']);
        return $user ?: null;
    }

    public function requireLogin(string $loginPage = '/account.php'): void {
        if (!$this->isLoggedIn()) {
            header("Location: {$loginPage}");
            exit;
        }
    }

    public function requireRole(string|array $roles, string $loginPage = '/account.php'): void {
        $this->requireLogin($loginPage);

        $allowed = (array) $roles;

        if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
            header("Location: {$loginPage}");
            exit;
        }
    }

    // ══════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════

    public function logAction(?int $userId, string $action, string $details, string $table, int $recordId): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $details,
            $table,
            $recordId,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function findByIdentifier(string $identifier): array|false {
        $stmt = $this->pdo->prepare("
            SELECT u.userId, u.first_name, u.last_name, u.password, u.status, ur.role
            FROM user u
            JOIN user_role ur ON ur.roleId = u.roleId
            WHERE u.username = :id OR u.email = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        return $stmt->fetch();
    }

    private function findById(int $userId): array|false {
        $stmt = $this->pdo->prepare("
            SELECT u.userId, u.first_name, u.last_name, u.middle_name,
                   u.phone_number, u.username, u.email, u.status,
                   u.last_login, u.created_at, ur.role, ur.roleId
            FROM user u
            JOIN user_role ur ON ur.roleId = u.roleId
            WHERE u.userId = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    private function usernameExists(string $username): bool {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ?");
        $stmt->execute([$username]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function emailExists(string $email): bool {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user WHERE email = ?");
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function startSession(array $user): void {
        session_regenerate_id(true);
        $_SESSION['userId']     = $user['userId'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name']  = $user['last_name'];
        $_SESSION['role']       = $user['role'];
    }

    private function updateLastLogin(int $userId): void {
        $this->pdo->prepare("UPDATE user SET last_login = NOW() WHERE userId = ?")
                  ->execute([$userId]);
    }

    private function validateAccountData(array $data): array {
        $required = ['first_name', 'last_name', 'username', 'email', 'password', 'roleId'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['valid' => false, 'message' => "The field '{$field}' is required."];
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'message' => 'Invalid email address.'];
        }

        return $this->validatePassword($data['password']);
    }

    private function validatePassword(string $password): array {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters.'];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter.'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number.'];
        }
        if (!preg_match('/[\W_]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character.'];
        }
        return ['valid' => true, 'message' => ''];
    }

    private function fail(string $message): array {
        return ['success' => false, 'message' => $message];
    }
}