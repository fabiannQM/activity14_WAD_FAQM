<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function getDbConnection(): PDO
{
    $dataPath = __DIR__ . '/data';
    if (!is_dir($dataPath)) {
        mkdir($dataPath, 0755, true);
    }

    $dbFile = $dataPath . '/notes.sqlite';
    $dsn = 'sqlite:' . $dbFile;
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    initializeDatabase($pdo);

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        author TEXT NOT NULL,
        created_at TEXT NOT NULL,
        body TEXT NOT NULL,
        category_id INTEGER NOT NULL,
        FOREIGN KEY(category_id) REFERENCES categories(id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS access_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        revoked_at TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');

    $defaultCategories = ['personal', 'work', 'school', 'ideas', 'other'];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name) VALUES (:name)');
    foreach ($defaultCategories as $name) {
        $stmt->execute([':name' => $name]);
    }
}

function sendJson($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getInput(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function getPathSegments(): array
{
    if (!empty($_SERVER['PATH_INFO'])) {
        $path = trim($_SERVER['PATH_INFO'], '/');
        return $path === '' ? [] : explode('/', $path);
    }

    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $script = $_SERVER['SCRIPT_NAME'];
    $base = dirname($script);
    $trimmed = preg_replace('#^' . preg_quote($script, '#') . '#', '', $uri);
    $trimmed = preg_replace('#^' . preg_quote($base, '#') . '#', '', $trimmed);
    $trimmed = trim($trimmed, '/');
    return $trimmed === '' ? [] : explode('/', $trimmed);
}

function getCategoryId(PDO $pdo, string $classification): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name');
    $stmt->execute([':name' => $classification]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
    $stmt->execute([':name' => $classification]);
    return (int)$pdo->lastInsertId();
}

function fetchNote(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT notes.id, notes.title, notes.author, notes.created_at, notes.body, categories.name AS classification
        FROM notes
        JOIN categories ON notes.category_id = categories.id
        WHERE notes.id = :id');
    $stmt->execute([':id' => $id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    return $note ?: null;
}

function listNotes(PDO $pdo): array
{
    $query = 'SELECT notes.id, notes.title, notes.author, notes.created_at, notes.body, categories.name AS classification
        FROM notes
        JOIN categories ON notes.category_id = categories.id';

    $params = [];
    if (isset($_GET['classification']) && trim($_GET['classification']) !== '') {
        $query .= ' WHERE categories.name = :classification';
        $params[':classification'] = trim($_GET['classification']);
    }

    $query .= ' ORDER BY notes.created_at DESC';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function listCategories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name FROM categories ORDER BY name');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function publicUser(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'created_at' => $user['created_at'],
    ];
}

function authenticatedUser(PDO $pdo): array
{
    $token = getBearerToken();
    if (!$token) {
        sendJson(['message' => 'Unauthenticated.'], 401);
    }

    $stmt = $pdo->prepare('SELECT users.id, users.name, users.email, users.created_at
        FROM access_tokens
        JOIN users ON access_tokens.user_id = users.id
        WHERE access_tokens.token = :token
            AND access_tokens.revoked_at IS NULL
            AND access_tokens.expires_at > :now');
    $stmt->execute([
        ':token' => hash('sha256', $token),
        ':now' => date('Y-m-d H:i:s'),
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        sendJson(['message' => 'Unauthenticated.'], 401);
    }

    return $user;
}

function signUp(PDO $pdo): void
{
    $input = getInput();
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        sendJson(['error' => 'name, valid email and password are required'], 422);
    }

    if (strlen($password) < 8) {
        sendJson(['error' => 'password must be at least 8 characters'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        sendJson(['error' => 'email already exists'], 422);
    }

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password, created_at) VALUES (:name, :email, :password, :created_at)');
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    sendJson(['message' => 'Successfully created user!'], 201);
}

function login(PDO $pdo): void
{
    $input = getInput();
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $rememberMe = filter_var($input['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        sendJson(['error' => 'email and password are required'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, name, email, password, created_at FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        sendJson(['message' => 'Unauthorized'], 401);
    }

    $plainToken = bin2hex(random_bytes(40));
    $expiresAt = date('Y-m-d H:i:s', strtotime($rememberMe ? '+1 week' : '+1 day'));
    $stmt = $pdo->prepare('INSERT INTO access_tokens (user_id, token, expires_at, created_at) VALUES (:user_id, :token, :expires_at, :created_at)');
    $stmt->execute([
        ':user_id' => $user['id'],
        ':token' => hash('sha256', $plainToken),
        ':expires_at' => $expiresAt,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    sendJson([
        'access_token' => $plainToken,
        'token_type' => 'Bearer',
        'expires_at' => $expiresAt,
        'user' => publicUser($user),
    ]);
}

function logout(PDO $pdo): void
{
    authenticatedUser($pdo);
    $token = getBearerToken();
    $stmt = $pdo->prepare('UPDATE access_tokens SET revoked_at = :revoked_at WHERE token = :token AND revoked_at IS NULL');
    $stmt->execute([
        ':revoked_at' => date('Y-m-d H:i:s'),
        ':token' => hash('sha256', $token),
    ]);

    sendJson(['message' => 'Successfully logged out']);
}

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];
$segments = getPathSegments();

if (empty($segments)) {
    sendJson([
        'message' => 'Notes API is running.',
        'routes' => [
            'POST /api.php/auth/signup',
            'POST /api.php/auth/login',
            'GET /api.php/auth/user',
            'GET /api.php/auth/logout',
            'GET /api.php/notes',
            'GET /api.php/notes/{id}',
            'POST /api.php/notes',
            'PUT /api.php/notes/{id}',
            'DELETE /api.php/notes/{id}',
            'GET /api.php/categories'
        ]
    ]);
}

$resource = $segments[0] ?? null;
$id = isset($segments[1]) ? (int)$segments[1] : null;

try {
    if ($resource === 'auth') {
        $action = $segments[1] ?? null;

        if ($action === 'signup' && $method === 'POST') {
            signUp($pdo);
        }

        if ($action === 'login' && $method === 'POST') {
            login($pdo);
        }

        if ($action === 'user' && $method === 'GET') {
            sendJson(publicUser(authenticatedUser($pdo)));
        }

        if ($action === 'logout' && $method === 'GET') {
            logout($pdo);
        }

        sendJson(['error' => 'Auth route not found'], 404);
    }

    if ($resource === 'categories') {
        if ($method !== 'GET') {
            sendJson(['error' => 'Method not allowed on /categories'], 405);
        }
        sendJson(['categories' => listCategories($pdo)]);
    }

    if ($resource !== 'notes') {
        sendJson(['error' => 'Resource not found'], 404);
    }

    switch ($method) {
        case 'GET':
            if ($id) {
                $note = fetchNote($pdo, $id);
                if (!$note) {
                    sendJson(['error' => 'Note not found'], 404);
                }
                sendJson($note);
            }
            sendJson(['notes' => listNotes($pdo)]);
            break;

        case 'POST':
            $input = getInput();
            $title = trim($input['title'] ?? '');
            $author = trim($input['author'] ?? '');
            $body = trim($input['body'] ?? '');
            $classification = trim($input['classification'] ?? '');
            $createdAt = trim($input['created_at'] ?? date('Y-m-d H:i:s'));

            if ($title === '' || $author === '' || $body === '' || $classification === '') {
                sendJson(['error' => 'title, author, body and classification are required'], 400);
            }

            $categoryId = getCategoryId($pdo, $classification);
            $stmt = $pdo->prepare('INSERT INTO notes (title, author, created_at, body, category_id) VALUES (:title, :author, :created_at, :body, :category_id)');
            $stmt->execute([
                ':title' => $title,
                ':author' => $author,
                ':created_at' => $createdAt,
                ':body' => $body,
                ':category_id' => $categoryId,
            ]);

            $noteId = (int)$pdo->lastInsertId();
            sendJson(['message' => 'Note created', 'id' => $noteId], 201);
            break;

        case 'PUT':
            if (!$id) {
                sendJson(['error' => 'Note ID is required for update'], 400);
            }
            $note = fetchNote($pdo, $id);
            if (!$note) {
                sendJson(['error' => 'Note not found'], 404);
            }

            $input = getInput();
            $title = trim($input['title'] ?? $note['title']);
            $author = trim($input['author'] ?? $note['author']);
            $body = trim($input['body'] ?? $note['body']);
            $classification = trim($input['classification'] ?? $note['classification']);

            if ($title === '' || $author === '' || $body === '' || $classification === '') {
                sendJson(['error' => 'title, author, body and classification are required'], 400);
            }

            $categoryId = getCategoryId($pdo, $classification);
            $stmt = $pdo->prepare('UPDATE notes SET title = :title, author = :author, body = :body, category_id = :category_id WHERE id = :id');
            $stmt->execute([
                ':title' => $title,
                ':author' => $author,
                ':body' => $body,
                ':category_id' => $categoryId,
                ':id' => $id,
            ]);

            sendJson(['message' => 'Note updated']);
            break;

        case 'DELETE':
            if (!$id) {
                sendJson(['error' => 'Note ID is required for delete'], 400);
            }

            $stmt = $pdo->prepare('DELETE FROM notes WHERE id = :id');
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() === 0) {
                sendJson(['error' => 'Note not found'], 404);
            }
            sendJson(['message' => 'Note deleted']);
            break;

        default:
            sendJson(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    sendJson(['error' => 'Server error', 'details' => $e->getMessage()], 500);
}
