<?php
/**
 * Gitea Repository Manager - git.sis1.dev
 * Single File PHP Application
 * Version: 3.0 — Token Login
 */

// ============================================================
// CONFIGURATION
// ============================================================
define('GITEA_BASE_URL', 'https://git.sis1.dev');
define('GITEA_API_BASE', GITEA_BASE_URL . '/api/v1');
define('APP_NAME', 'Gitea Manager');
define('APP_DOMAIN', 'git.sis1.dev');
define('APP_VERSION', '5.0');
define('CACHE_DURATION', 300); // 5 minutes
define('PER_PAGE', 20);

// ============================================================
// SESSION START
// ============================================================
session_start();

// ============================================================
// AUTH HELPER — validate token against Gitea API
// ============================================================
function validateToken(string $token, string $baseUrl): array|false {
    if (empty($token)) return false;
    $url = rtrim($baseUrl, '/') . '/api/v1/user';
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: token ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'GiteaManager/3.0',
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return false;
    $user = json_decode($body, true);
    return (is_array($user) && isset($user['login'])) ? $user : false;
}

// ============================================================
// ACTION HANDLER — process POST before any output
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── LOGIN via token ──────────────────────────────────────
    if ($action === 'login') {
        $inputToken = trim($_POST['token'] ?? '');
        $inputUrl   = rtrim(trim($_POST['gitea_url'] ?? GITEA_BASE_URL), '/');

        if (empty($inputToken)) {
            $_SESSION['login_error'] = 'Token tidak boleh kosong.';
        } else {
            $user = validateToken($inputToken, $inputUrl);
            if ($user) {
                // Authenticated — store in session
                $_SESSION['gitea_token']   = $inputToken;
                $_SESSION['gitea_url']     = $inputUrl;
                $_SESSION['per_page']      = PER_PAGE;
                $_SESSION['auth_user']     = $user;       // cached user data
                $_SESSION['login_time']    = time();
                unset($_SESSION['login_error']);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?page=dashboard');
                exit;
            } else {
                $_SESSION['login_error'] = 'Token tidak valid atau tidak dapat terhubung ke server Gitea.';
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=login');
        exit;
    }

    // ── LOGOUT ───────────────────────────────────────────────
    if ($action === 'logout') {
        $keep = [];
        session_destroy();
        session_start();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=login&msg=logged_out');
        exit;
    }

    // ── SAVE SETTINGS (must be logged in) ───────────────────
    if ($action === 'save_config' && isset($_SESSION['gitea_token'])) {
        $_SESSION['per_page'] = (int)($_POST['per_page'] ?? PER_PAGE);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=settings&msg=saved');
        exit;
    }

    // ── CLEAR CACHE (must be logged in) ─────────────────────
    if ($action === 'clear_cache' && isset($_SESSION['gitea_token'])) {
        $cacheDir = sys_get_temp_dir() . '/gitea_cache';
        if (is_dir($cacheDir)) {
            foreach (glob("$cacheDir/*.json") ?: [] as $f) @unlink($f);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=settings&msg=cache_cleared');
        exit;
    }
}

// ============================================================
// AUTHENTICATION GUARD
// ============================================================
$giteaToken  = $_SESSION['gitea_token'] ?? '';
$giteaUrl    = $_SESSION['gitea_url']   ?? GITEA_BASE_URL;
$perPage     = $_SESSION['per_page']    ?? PER_PAGE;
$authUser    = $_SESSION['auth_user']   ?? null;
$isLoggedIn  = !empty($giteaToken) && !empty($authUser);

$page = $_GET['page'] ?? ($isLoggedIn ? 'dashboard' : 'login');

// Public pages (no auth required)
$publicPages = ['login'];

if (!$isLoggedIn && !in_array($page, $publicPages)) {
    // Redirect to login, remember intended destination
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=login');
    exit;
}

// ============================================================
// GITEA API CLASS
// ============================================================
class GiteaAPI {
    private string $baseUrl;

    public function getBaseUrl(): string { return $this->baseUrl; }
    private string $token;
    private string $cacheDir;

    public function __construct(string $baseUrl, string $token = '') {
        $this->baseUrl  = rtrim($baseUrl, '/') . '/api/v1';
        $this->token    = $token;
        $this->cacheDir = sys_get_temp_dir() . '/gitea_cache';
        if (!is_dir($this->cacheDir)) mkdir($this->cacheDir, 0755, true);
    }

    /** Delete all cache files for this token */
    public function purgeCache(): void {
        $tokenHash = $this->token ? substr(md5($this->token), 0, 8) : 'anon';
        foreach (glob($this->cacheDir . '/' . $tokenHash . '_*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Delete ALL cache files regardless of token */
    public function purgeAllCache(): void {
        foreach (glob($this->cacheDir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function getCacheKey(string $url): string {
        // Include token hash in cache key — different tokens = different cache
        $tokenHash = $this->token ? substr(md5($this->token), 0, 8) : 'anon';
        return $this->cacheDir . '/' . $tokenHash . '_' . md5($url) . '.json';
    }

    private function request(string $endpoint, array $params = [], bool $useCache = true): array|null {
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) $url .= '?' . http_build_query($params);

        $cacheFile = $this->getCacheKey($url);

        // Serve from cache if fresh
        if ($useCache && file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < CACHE_DURATION) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if ($cached !== null) return $cached;
            }
        }

        // Make HTTP request
        $ch      = curl_init();
        $headers = ['Accept: application/json'];
        if ($this->token) {
            $headers[] = 'Authorization: token ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL             => $url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            // Keep Authorization header through redirects
            CURLOPT_UNRESTRICTED_AUTH => true,
            CURLOPT_USERAGENT       => 'GiteaManager/3.0',
            CURLOPT_ENCODING        => '',   // Accept gzip/deflate
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Only cache successful responses
        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $data = json_decode($response, true);
        if ($data !== null && $useCache) {
            file_put_contents($cacheFile, $response, LOCK_EX);
        }
        return $data;
    }

    public function getVersion(): array|null {
        return $this->request('/version', [], false);
    }

    public function getCurrentUser(): array|null {
        return $this->request('/user', [], false);
    }

    public function getNodeInfo(): array|null {
        return $this->request('/settings/api', [], false);
    }

    // ── request with response headers capture ───────────────────────
    private function requestWithHeaders(string $endpoint, array $params = []): array {
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) $url .= '?' . http_build_query($params);

        $ch      = curl_init();
        $headers = ['Accept: application/json'];
        if ($this->token) $headers[] = 'Authorization: token ' . $this->token;

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL             => $url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_UNRESTRICTED_AUTH => true,
            CURLOPT_USERAGENT       => 'GiteaManager/3.0',
            CURLOPT_ENCODING        => '',
            CURLOPT_HEADERFUNCTION  => function($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            return ['data' => null, 'headers' => [], 'total' => 0];
        }
        $data = json_decode($response, true);
        $total = (int)($responseHeaders['x-total-count'] ?? 0);
        return ['data' => $data, 'headers' => $responseHeaders, 'total' => $total];
    }

    // ── search repos (single page) ───────────────────────────────────
    public function searchRepos(string $q = '', int $page = 1, int $limit = 20,
                                string $sort = 'updated', string $order = 'desc'): array|null {
        $params = ['limit' => $limit, 'page' => $page, 'sort' => $sort, 'order' => $order];
        if ($q) $params['q'] = $q;
        return $this->request('/repos/search', $params);
    }

    // ── fetch ALL repos (all pages) ──────────────────────────────────
    public function fetchAllRepos(string $sort = 'updated', string $q = ''): array {
        $cacheKey  = $this->getCacheKey('fetchAllRepos_' . $sort . '_' . $q);
        if (file_exists($cacheKey) && (time() - filemtime($cacheKey) < CACHE_DURATION)) {
            $cached = json_decode(file_get_contents($cacheKey), true);
            if ($cached !== null) return $cached;
        }

        $allRepos = [];
        $page     = 1;
        $limit    = 50;

        while (true) {
            $params = ['limit' => $limit, 'page' => $page, 'sort' => $sort, 'order' => 'desc'];
            if ($q) $params['q'] = $q;
            $result = $this->requestWithHeaders('/repos/search', $params);
            $data   = $result['data']['data'] ?? [];
            if (empty($data)) break;
            $allRepos = array_merge($allRepos, $data);
            if (count($data) < $limit) break;
            $page++;
            if ($page > 20) break; // safety cap
        }

        if (!empty($allRepos)) {
            file_put_contents($cacheKey, json_encode($allRepos), LOCK_EX);
        }
        return $allRepos;
    }

    // ── extract unique owners/users from repos ───────────────────────
    public function getUsersFromRepos(array $repos): array {
        $owners = [];
        foreach ($repos as $r) {
            $o     = $r['owner'] ?? [];
            $login = $o['login'] ?? '';
            if (!$login) continue;
            if (!isset($owners[$login])) {
                $owners[$login] = [
                    'login'       => $login,
                    'full_name'   => $o['full_name']  ?? '',
                    'avatar_url'  => $o['avatar_url'] ?? '',
                    'html_url'    => $o['html_url']   ?? '',
                    'visibility'  => $o['visibility'] ?? '',
                    'is_admin'    => $o['is_admin']   ?? false,
                    'repo_count'  => 0,
                    'source'      => 'repo_owner',
                ];
            }
            $owners[$login]['repo_count']++;
        }
        // Sort by repo_count desc
        uasort($owners, fn($a, $b) => $b['repo_count'] - $a['repo_count']);
        return array_values($owners);
    }

    // ── fetch all orgs (all pages) ───────────────────────────────────
    public function fetchAllOrgs(): array {
        $allOrgs = [];
        $page    = 1;
        while (true) {
            $result = $this->request('/admin/orgs', ['page' => $page, 'limit' => 50]);
            if (!is_array($result) || empty($result)) break;
            // If result is an error object (403 etc.), stop
            if (isset($result['message'])) break;
            $allOrgs = array_merge($allOrgs, $result);
            if (count($result) < 50) break;
            $page++;
            if ($page > 10) break;
        }

        // Fallback: extract orgs from repo owners when admin/orgs is unavailable
        if (empty($allOrgs)) {
            $allRepos = $this->fetchAllRepos('updated');
            $orgMap   = [];
            foreach ($allRepos as $r) {
                $o    = $r['owner'] ?? [];
                $login= $o['login'] ?? '';
                // Gitea returns owner_type: "User" or "Organization"
                $type = $o['type'] ?? ($o['owner_type'] ?? '');
                // Heuristic: check /orgs/{login} - if it succeeds it's an org
                if (!$login) continue;
                if (!isset($orgMap[$login])) {
                    $orgMap[$login] = [
                        'id'         => $o['id']        ?? 0,
                        'username'   => $login,
                        'name'       => $o['full_name'] ?? $login,
                        'avatar_url' => $o['avatar_url'] ?? '',
                        'html_url'   => $o['html_url']   ?? '',
                        'visibility' => $o['visibility'] ?? 'public',
                        'repo_count' => 0,
                        'owner_type' => $type,
                    ];
                }
                $orgMap[$login]['repo_count']++;
            }
            // Only keep entries that look like orgs (Gitea org owners have is_admin=false typically)
            // Best filter: try /orgs/{login} for top owners
            $candidates = array_values($orgMap);
            usort($candidates, fn($a,$b) => $b['repo_count'] - $a['repo_count']);
            foreach ($candidates as $c) {
                $orgInfo = $this->request('/orgs/' . rawurlencode($c['username']));
                if (is_array($orgInfo) && !isset($orgInfo['message']) && isset($orgInfo['username'])) {
                    $allOrgs[] = array_merge($c, $orgInfo);
                }
            }
        }
        return $allOrgs;
    }

    public function getRepo(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}");
    }

    public function getRepoTopics(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/topics");
    }

    public function getRepoLanguages(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/languages");
    }

    public function getRepoContributors(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/contributors");
    }

    public function getBranches(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/branches", ['limit' => 50]);
    }

    public function getReleases(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/releases", ['limit' => 10]);
    }

    public function getIssues(string $owner, string $repo, string $state = 'open'): array|null {
        return $this->request("/repos/{$owner}/{$repo}/issues", ['state' => $state, 'type' => 'issues', 'limit' => 10]);
    }

    public function getCommits(string $owner, string $repo, int $limit = 10): array|null {
        return $this->request("/repos/{$owner}/{$repo}/commits", ['limit' => $limit]);
    }

    public function getUser(string $username): array|null {
        return $this->request("/users/{$username}");
    }

    public function getUserRepos(string $username, int $page = 1, int $limit = 50): array|null {
        return $this->request("/users/{$username}/repos", ['page' => $page, 'limit' => $limit]);
    }

    public function getUserOrgs(string $username): array|null {
        return $this->request("/users/{$username}/orgs");
    }

    public function listOrgs(int $page = 1, int $limit = 50): array|null {
        return $this->request('/admin/orgs', ['page' => $page, 'limit' => $limit]);
    }

    public function getOrg(string $org): array|null {
        return $this->request("/orgs/{$org}");
    }

    public function getOrgRepos(string $org, int $page = 1, int $limit = 50): array|null {
        return $this->request("/orgs/{$org}/repos", ['page' => $page, 'limit' => $limit]);
    }

    public function getExploreUsers(int $page = 1, int $limit = 50): array|null {
        // Fallback: try admin/users first; if 403 return null (we'll use getUsersFromRepos)
        $result = $this->request('/admin/users', ['page' => $page, 'limit' => $limit]);
        if (is_array($result) && isset($result['message'])) return null; // scope error
        return $result;
    }

    public function getStargazers(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/stargazers");
    }

    public function getContents(string $owner, string $repo, string $path = ''): array|null {
        return $this->request("/repos/{$owner}/{$repo}/contents/{$path}");
    }

    public function searchUsers(string $q, int $limit = 50): array|null {
        return $this->request('/users/search', ['q' => $q, 'limit' => $limit]);
    }

    public function getTags(string $owner, string $repo): array|null {
        return $this->request("/repos/{$owner}/{$repo}/tags", ['limit' => 10]);
    }

    // ── archive download URL ─────────────────────────────────────────
    public function getArchiveUrl(string $owner, string $repo, string $ref, string $format = 'zip'): string {
        // Gitea archive endpoint: /repos/{owner}/{repo}/archive/{ref}.{format}
        $base = rtrim(str_replace('/api/v1', '', $this->baseUrl), '/');
        return "{$base}/{$owner}/{$repo}/archive/{$ref}.{$format}";
    }

    // ── proxy download a URL and stream to client ────────────────────
    public function proxyDownload(string $url, string $filename): void {
        $ch = curl_init();
        $headers = ['Accept: application/octet-stream'];
        if ($this->token) $headers[] = 'Authorization: token ' . $this->token;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_UNRESTRICTED_AUTH => true,
            CURLOPT_USERAGENT      => 'GiteaManager/3.0',
            CURLOPT_ENCODING       => '',
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!$body || $httpCode < 200 || $httpCode >= 400) {
            http_response_code(502);
            echo json_encode(['error' => "Upstream returned HTTP $httpCode"]);
            return;
        }

        $safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . mb_strlen($body, '8bit'));
        header('Cache-Control: no-store');
        echo $body;
    }

    // ── get raw file content ─────────────────────────────────────────
    public function getRawFile(string $owner, string $repo, string $path, string $ref = ''): array|null {
        $endpoint = "/repos/{$owner}/{$repo}/raw/" . ltrim($path, '/');
        $params   = $ref ? ['ref' => $ref] : [];
        // Raw endpoint returns plain text, not JSON — handle separately
        $url  = $this->baseUrl . $endpoint;
        if ($params) $url .= '?' . http_build_query($params);
        $ch   = curl_init();
        $hdrs = ['Accept: */*'];
        if ($this->token) $hdrs[] = 'Authorization: token ' . $this->token;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'GiteaManager/3.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code < 200 || $code >= 400 || !$body) return null;
        return ['content' => $body, 'content_type' => $ct, 'http_code' => $code];
    }

    // ── get repo contents (file tree) ────────────────────────────────
    public function getTree(string $owner, string $repo, string $path = '', string $ref = ''): array|null {
        $cleanPath = trim($path, '/');
        $endpoint  = "/repos/{$owner}/{$repo}/contents" . ($cleanPath !== '' ? '/' . $cleanPath : '');
        $params    = $ref ? ['ref' => $ref] : [];
        $result    = $this->request($endpoint, $params, false);
        if (is_array($result) && isset($result['message'])) return null;
        return $result;
    }

    // ── get last commit for a specific file/path ─────────────────────
    public function getLastCommit(string $owner, string $repo, string $path = '', string $ref = ''): array|null {
        $params = ['limit' => 1];
        if ($path !== '') $params['path'] = $path;
        if ($ref  !== '') $params['sha']  = $ref;
        // Use short cache (60s) for commit data to avoid hammering Gitea API on every browse
        $result = $this->request("/repos/{$owner}/{$repo}/commits", $params, true);
        if (!is_array($result) || empty($result)) return null;
        // Handle both array-of-commits and error object
        if (isset($result['message'])) return null;
        $c = $result[0] ?? null;
        if (!$c) return null;
        return [
            'sha'     => $c['sha']                            ?? '',
            'message' => strtok($c['commit']['message'] ?? '', "\n"),
            'date'    => $c['commit']['committer']['date']    ?? $c['commit']['author']['date'] ?? '',
            'author'  => $c['commit']['author']['name']       ?? '',
            'avatar'  => $c['author']['avatar_url']           ?? '',
            'url'     => $c['html_url']                       ?? '',
        ];
    }

    // ── enrich tree items with commit message (batch, max N items) ───
    public function enrichTreeWithCommits(string $owner, string $repo, array $tree, string $ref = '', int $max = 25): array {
        $count = 0;
        foreach ($tree as &$item) {
            // last_committer_date already present from contents API
            // Only fetch commit message if not already there (it never is from contents API)
            if ($count >= $max) break;
            $path   = $item['path'] ?? '';
            $commit = $this->getLastCommit($owner, $repo, $path, $ref);
            if ($commit) {
                $item['_commit_message'] = $commit['message'];
                $item['_commit_sha']     = substr($commit['sha'], 0, 7);
                $item['_commit_url']     = $commit['url'];
                $item['_commit_author']  = $commit['author'];
            }
            $count++;
        }
        unset($item);
        return $tree;
    }

    // ── get single file info + content (base64 encoded) ─────────────
    public function getFileInfo(string $owner, string $repo, string $path, string $ref = ''): array|null {
        $cleanPath = trim($path, '/');
        $endpoint  = "/repos/{$owner}/{$repo}/contents" . ($cleanPath !== '' ? '/' . $cleanPath : '');
        $params    = $ref ? ['ref' => $ref] : [];
        $result    = $this->request($endpoint, $params, false);
        if (is_array($result) && isset($result['message'])) return null;
        return $result;
    }

    // ── update/edit existing file (PUT) ─────────────────────────────
    public function updateFile(string $owner, string $repo, string $path, string $content,
                               string $sha, string $message, string $branch = ''): array|null {
        $endpoint = "/repos/{$owner}/{$repo}/contents/" . ltrim($path, '/');
        $payload  = [
            'message' => $message ?: 'Update ' . basename($path),
            'content' => base64_encode($content),
            'sha'     => $sha,
        ];
        if ($branch) $payload['branch'] = $branch;
        return $this->requestPut($endpoint, $payload);
    }

    // ── create new file (POST) ───────────────────────────────────────
    public function createFile(string $owner, string $repo, string $path, string $content,
                               string $message, string $branch = ''): array|null {
        $endpoint = "/repos/{$owner}/{$repo}/contents/" . ltrim($path, '/');
        $payload  = [
            'message' => $message ?: 'Create ' . basename($path),
            'content' => base64_encode($content),
        ];
        if ($branch) $payload['branch'] = $branch;
        return $this->requestPost($endpoint, $payload);
    }

    // ── delete file (DELETE) ─────────────────────────────────────────
    public function deleteFile(string $owner, string $repo, string $path, string $sha,
                               string $message, string $branch = ''): array|null {
        $endpoint = "/repos/{$owner}/{$repo}/contents/" . ltrim($path, '/');
        $payload  = [
            'message' => $message ?: 'Delete ' . basename($path),
            'sha'     => $sha,
        ];
        if ($branch) $payload['branch'] = $branch;
        return $this->requestDelete($endpoint, $payload);
    }

    // ── HTTP helpers (PUT / POST / DELETE with JSON body) ────────────
    private function requestPut(string $endpoint, array $data): array|null {
        return $this->requestWithBody('PUT', $endpoint, $data);
    }
    private function requestPost(string $endpoint, array $data): array|null {
        return $this->requestWithBody('POST', $endpoint, $data);
    }
    private function requestDelete(string $endpoint, array $data): array|null {
        return $this->requestWithBody('DELETE', $endpoint, $data);
    }
    private function requestWithBody(string $method, string $endpoint, array $data): array|null {
        $url  = $this->baseUrl . $endpoint;
        $body = json_encode($data);
        $ch   = curl_init();
        $hdrs = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . mb_strlen($body, '8bit'),
        ];
        if ($this->token) $hdrs[] = 'Authorization: token ' . $this->token;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'GiteaManager/3.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$resp) return null;
        $decoded = json_decode($resp, true);
        if ($code >= 400) return ['_error' => true, '_code' => $code, '_msg' => $decoded['message'] ?? $resp];
        return $decoded;
    }

    // ── get activities/feeds ─────────────────────────────────────────
    public function getUserFeeds(string $username, int $limit = 30): array|null {
        return $this->request("/users/{$username}/heatmap", []);
    }

    public function getNotifications(int $limit = 20): array|null {
        return $this->request('/notifications', ['limit' => $limit, 'all' => 'true'], false);
    }

    public function getRepoActivity(string $owner, string $repo, int $limit = 20): array|null {
        return $this->request("/repos/{$owner}/{$repo}/commits", ['limit' => $limit]);
    }

    // ── global event feed (recent activity across all repos) ─────────
    public function getGlobalActivity(int $limit = 30): array {
        // Use recent commits from top repos as "activity events"
        $events   = [];
        $allRepos = $this->fetchAllRepos('updated');
        $topRepos = array_slice($allRepos, 0, 8); // scan top 8 recently updated
        foreach ($topRepos as $r) {
            $owner = $r['owner']['login'] ?? '';
            $name  = $r['name'] ?? '';
            if (!$owner || !$name) continue;
            $commits = $this->request("/repos/{$owner}/{$name}/commits", ['limit' => 5], false);
            if (!is_array($commits)) continue;
            foreach ($commits as $c) {
                $events[] = [
                    'type'       => 'push',
                    'repo'       => $r['full_name'],
                    'repo_owner' => $owner,
                    'repo_name'  => $name,
                    'repo_url'   => $r['html_url'] ?? '',
                    'actor'      => $c['commit']['author']['name'] ?? $owner,
                    'actor_url'  => $r['owner']['html_url'] ?? '',
                    'avatar_url' => $r['owner']['avatar_url'] ?? '',
                    'message'    => strtok($c['commit']['message'] ?? '', "\n"),
                    'sha'        => substr($c['sha'] ?? '', 0, 8),
                    'sha_full'   => $c['sha'] ?? '',
                    'url'        => ($r['html_url'] ?? '') . '/commit/' . ($c['sha'] ?? ''),
                    'created_at' => $c['commit']['author']['date'] ?? '',
                    'private'    => $r['private'] ?? false,
                ];
            }
            if (count($events) >= $limit) break;
        }
        // Also add recent release events
        foreach (array_slice($allRepos, 0, 5) as $r) {
            $owner = $r['owner']['login'] ?? '';
            $name  = $r['name'] ?? '';
            if (!$owner || !$name) continue;
            $releases = $this->request("/repos/{$owner}/{$name}/releases", ['limit' => 2], false);
            if (!is_array($releases)) continue;
            foreach ($releases as $rel) {
                $events[] = [
                    'type'       => 'release',
                    'repo'       => $r['full_name'],
                    'repo_owner' => $owner,
                    'repo_name'  => $name,
                    'repo_url'   => $r['html_url'] ?? '',
                    'actor'      => $rel['author']['login'] ?? $owner,
                    'actor_url'  => $r['owner']['html_url'] ?? '',
                    'avatar_url' => $rel['author']['avatar_url'] ?? $r['owner']['avatar_url'] ?? '',
                    'message'    => 'Released ' . ($rel['name'] ?: $rel['tag_name'] ?? ''),
                    'sha'        => $rel['tag_name'] ?? '',
                    'sha_full'   => '',
                    'url'        => $rel['html_url'] ?? '',
                    'created_at' => $rel['created_at'] ?? '',
                    'private'    => $r['private'] ?? false,
                ];
            }
        }
        // Sort by date desc
        usort($events, fn($a,$b) => strtotime($b['created_at']??'0') - strtotime($a['created_at']??'0'));
        return array_slice($events, 0, $limit);
    }

    // ── live stats endpoint ──────────────────────────────────────────
    public function getLiveStats(): array {
        $allRepos    = $this->fetchAllRepos('updated');
        $totalStars  = 0; $totalForks = 0; $totalIssues = 0;
        $publicCount = 0; $privateCount = 0;
        foreach ($allRepos as $r) {
            $totalStars  += $r['stars_count'] ?? 0;
            $totalForks  += $r['forks_count'] ?? 0;
            $totalIssues += $r['open_issues_count'] ?? 0;
            if ($r['private']) $privateCount++; else $publicCount++;
        }
        return [
            'repos'   => count($allRepos),
            'public'  => $publicCount,
            'private' => $privateCount,
            'stars'   => $totalStars,
            'forks'   => $totalForks,
            'issues'  => $totalIssues,
            'ts'      => time(),
        ];
    }
}

// ============================================================
// ROUTER & INIT
// ============================================================
$api         = new GiteaAPI($giteaUrl, $giteaToken);
$msg         = $_GET['msg'] ?? '';

// Force-clear cache for this request if ?nocache=1
if (!empty($_GET['nocache'])) {
    $api->purgeCache();
}

// Use cached user from session (avoids extra API call every request)
$currentUser = $authUser;

// Fetch site version only when logged in
$siteVersion = $isLoggedIn ? $api->getVersion() : null;

// ============================================================
// HELPERS
// ============================================================
function timeAgo(?string $date): string {
    if (!$date) return '—';
    $diff = time() - strtotime($date);
    if ($diff < 60)       return $diff . 's ago';
    if ($diff < 3600)     return floor($diff/60) . 'm ago';
    if ($diff < 86400)    return floor($diff/3600) . 'h ago';
    if ($diff < 2592000)  return floor($diff/86400) . 'd ago';
    if ($diff < 31536000) return floor($diff/2592000) . 'mo ago';
    return floor($diff/31536000) . 'y ago';
}

function formatNumber(int $n): string {
    if ($n >= 1000000) return round($n/1000000, 1) . 'M';
    if ($n >= 1000)    return round($n/1000, 1) . 'K';
    return (string)$n;
}

function langColor(string $lang): string {
    $colors = [
        'PHP'        => '#4F5D95',
        'JavaScript' => '#F7DF1E',
        'TypeScript' => '#3178C6',
        'Python'     => '#3776AB',
        'Go'         => '#00ADD8',
        'Rust'       => '#DEA584',
        'Java'       => '#ED8B00',
        'C'          => '#555555',
        'C++'        => '#F34B7D',
        'C#'         => '#178600',
        'Ruby'       => '#CC342D',
        'Swift'      => '#FA7343',
        'Kotlin'     => '#7F52FF',
        'Shell'      => '#89E051',
        'HTML'       => '#E34C26',
        'CSS'        => '#1572B6',
        'Vue'        => '#41B883',
        'Dockerfile' => '#384D54',
        'Makefile'   => '#427819',
        'YAML'       => '#CB171E',
        'Markdown'   => '#083FA1',
    ];
    return $colors[$lang] ?? '#6B7280';
}

function visibility_badge(bool $private, bool $fork = false): string {
    if ($fork) return '<span class="badge badge-fork">⑂ Fork</span>';
    if ($private) return '<span class="badge badge-private">🔒 Private</span>';
    return '<span class="badge badge-public">🌐 Public</span>';
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function giteaLink(string $path): string {
    global $giteaUrl;
    return rtrim($giteaUrl, '/') . '/' . ltrim($path, '/');
}

// ============================================================
// PAGE DATA
// ============================================================
$pageData = [];
$currentSearch = $_GET['q'] ?? '';
$currentSort   = $_GET['sort'] ?? 'updated';
$currentPage   = max(1, (int)($_GET['p'] ?? 1));

switch ($page) {
    case 'repos':
        // Fetch ALL repos, no pagination — show everything at once
        $allRepos = $api->fetchAllRepos($currentSort, $currentSearch);
        $pageData['all_repos']   = $allRepos;
        $pageData['repos']       = $allRepos;   // pass full list to template
        $pageData['total_repos'] = count($allRepos);
        $pageData['total']       = count($allRepos);
        break;

    case 'dashboard':
        // Fetch ALL repos across all pages (handles 3+ pages of 50 each)
        $allRepos = $api->fetchAllRepos($currentSort, $currentSearch);
        $pageData['all_repos']   = $allRepos;                          // full list for stats
        $pageData['total_repos'] = count($allRepos);
        // PHP-side pagination: slice the full list for dashboard recent list
        $pageData['repos']  = array_slice($allRepos, ($currentPage - 1) * $perPage, $perPage);
        $pageData['total']  = $pageData['total_repos'];
        break;

    case 'repo':
        $owner = trim($_GET['owner'] ?? '');
        $repo  = trim($_GET['repo']  ?? '');
        if ($owner && $repo) {
            $repoData = $api->getRepo($owner, $repo);

            // Fallback: kalau getRepo null, ambil dari cache search
            if ($repoData === null) {
                $fallback = $api->searchRepos($repo, 1, 5);
                foreach ($fallback['data'] ?? [] as $r) {
                    if (strcasecmp($r['owner']['login'], $owner) === 0
                        && strcasecmp($r['name'], $repo) === 0) {
                        $repoData = $r;
                        break;
                    }
                }
            }

            $pageData['repo'] = $repoData;

            if ($repoData) {
                // Parallel fetch sub-resources; skip if repo is empty
                $isEmpty = $repoData['empty'] ?? false;
                $pageData['branches']  = $isEmpty ? [] : ($api->getBranches($owner, $repo) ?? []);
                $pageData['releases']  = $api->getReleases($owner, $repo) ?? [];
                $pageData['issues']    = $isEmpty ? [] : ($api->getIssues($owner, $repo, 'open') ?? []);
                $pageData['commits']   = $isEmpty ? [] : ($api->getCommits($owner, $repo, 5) ?? []);
                $pageData['languages'] = $isEmpty ? [] : ($api->getRepoLanguages($owner, $repo) ?? []);
                $pageData['topics']    = $api->getRepoTopics($owner, $repo) ?? [];
                $pageData['tags']      = $api->getTags($owner, $repo) ?? [];
                // Root file tree (for file browser card)
                $browseRef = trim($_GET['ref'] ?? '');
                $browsePath = trim($_GET['path'] ?? '');
                $pageData['browse_ref']  = $browseRef;
                $pageData['browse_path'] = $browsePath;
                if (!$isEmpty) {
                    $tree = $api->getTree($owner, $repo, $browsePath, $browseRef);
                    // Sort: dirs first, then files alphabetically
                    if (is_array($tree)) {
                        usort($tree, function($a, $b) {
                            $ta = ($a['type'] ?? 'file') === 'dir' ? 0 : 1;
                            $tb = ($b['type'] ?? 'file') === 'dir' ? 0 : 1;
                            return $ta !== $tb ? $ta - $tb : strcmp($a['name'] ?? '', $b['name'] ?? '');
                        });
                        // Enrich with last commit message per file/dir
                        $tree = $api->enrichTreeWithCommits($owner, $repo, $tree, $browseRef, 30);
                    }
                    $pageData['file_tree'] = $tree ?? [];
                } else {
                    $pageData['file_tree'] = [];
                }
            }
        }
        break;

    case 'users':
        // /admin/users returns 403 (token lacks read:admin) → extract owners from all repos
        $adminUsers = $api->getExploreUsers(1, 50);
        if (is_array($adminUsers) && !empty($adminUsers)) {
            // Admin scope available — paginate through all admin users
            $allAdminUsers = $adminUsers;
            $uPage = 2;
            while (count($adminUsers) >= 50) {
                $adminUsers = $api->getExploreUsers($uPage, 50);
                if (!is_array($adminUsers) || empty($adminUsers)) break;
                $allAdminUsers = array_merge($allAdminUsers, $adminUsers);
                $uPage++;
                if ($uPage > 20) break;
            }
            $pageData['users'] = $allAdminUsers;
        } else {
            // Fallback: derive unique owners from full repo list
            $allReposForUsers = $api->fetchAllRepos('updated');
            $pageData['users'] = $api->getUsersFromRepos($allReposForUsers);
        }
        $pageData['total_users'] = count($pageData['users']);
        break;

    case 'user':
        $username = $_GET['username'] ?? '';
        if ($username) {
            $pageData['user']  = $api->getUser($username);
            $pageData['repos'] = $api->getUserRepos($username, 1, 30);
            $pageData['orgs']  = $api->getUserOrgs($username);
        }
        break;

    case 'orgs':
        // Fetch ALL orgs across all pages
        $allOrgs = $api->fetchAllOrgs();
        $pageData['orgs']       = $allOrgs;
        $pageData['total_orgs'] = count($allOrgs);
        break;

    case 'org':
        $orgName = $_GET['org'] ?? '';
        if ($orgName) {
            $pageData['org']   = $api->getOrg($orgName);
            $pageData['repos'] = $api->getOrgRepos($orgName, 1, 30);
        }
        break;

    case 'settings':
        break;

    // ── File browser / viewer / editor ────────────────────────────
    case 'file':
        /*
         * Sub-actions via $_GET['action']:
         *   (none / browse) — directory listing or file view
         *   edit            — show editor form
         *   new             — show new-file form
         *   save            — POST: commit file update or create
         *   delete          — POST: delete file
         */
        $fOwner  = trim($_GET['owner']  ?? '');
        $fRepo   = trim($_GET['repo']   ?? '');
        $fPath   = trim($_GET['path']   ?? '');
        $fRef    = trim($_GET['ref']    ?? '');
        $fAction = trim($_GET['action'] ?? '');

        if (!$fOwner || !$fRepo) {
            header('Location: ?page=repos'); exit;
        }

        // ── POST: save (update or create) ──────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fAction === 'save') {
            $postPath    = trim(trim($_POST['path'] ?? $fPath), '/'); // strip leading+trailing slashes
            $postContent = $_POST['content']         ?? '';
            $postMsg     = trim($_POST['message']    ?? '');
            $postSha     = trim($_POST['sha']        ?? '');
            $postBranch  = trim($_POST['branch']     ?? '');
            $isNew       = ($_POST['is_new']         ?? '0') === '1';
            $nextRef     = $postBranch ?: $fRef;

            if (empty($postPath)) {
                $_SESSION['flash_error'] = 'Path file tidak boleh kosong.';
                header('Location: ?page=file&owner=' . urlencode($fOwner) . '&repo=' . urlencode($fRepo)
                    . '&path=' . urlencode($fPath) . '&ref=' . urlencode($fRef) . '&action=' . ($isNew ? 'new' : 'edit'));
                exit;
            }

            if ($isNew || $postSha === '') {
                $result = $api->createFile($fOwner, $fRepo, $postPath, $postContent, $postMsg, $postBranch);
            } else {
                $result = $api->updateFile($fOwner, $fRepo, $postPath, $postContent, $postSha, $postMsg, $postBranch);
            }

            if ($result !== null && empty($result['_error'])) {
                $_SESSION['flash_success'] = 'File berhasil disimpan: ' . htmlspecialchars($postPath);
                $redirectPath = dirname($postPath);
                $redirectPath = ($redirectPath === '.') ? '' : $redirectPath;
                header('Location: ?page=file&owner=' . urlencode($fOwner) . '&repo=' . urlencode($fRepo)
                    . '&path=' . urlencode($redirectPath) . '&ref=' . urlencode($nextRef));
            } else {
                $errMsg = is_array($result) ? ($result['_msg'] ?? 'API error') : 'Koneksi gagal atau server tidak merespons';
                $_SESSION['flash_error'] = 'Gagal menyimpan file: ' . htmlspecialchars($errMsg);
                header('Location: ?page=file&owner=' . urlencode($fOwner) . '&repo=' . urlencode($fRepo)
                    . '&path=' . urlencode($postPath) . '&ref=' . urlencode($nextRef) . '&action=' . ($isNew ? 'new' : 'edit'));
            }
            exit;
        }

        // ── POST: delete ────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fAction === 'delete') {
            $postPath   = trim(trim($_POST['path'] ?? $fPath), '/');
            $postSha    = trim($_POST['sha']     ?? '');
            $postMsg    = trim($_POST['message'] ?? 'Delete ' . basename($fPath));
            $postBranch = trim($_POST['branch']  ?? '');
            $result = $api->deleteFile($fOwner, $fRepo, $postPath, $postSha, $postMsg, $postBranch);
            if ($result !== null && empty($result['_error'])) {
                $_SESSION['flash_success'] = 'File dihapus: ' . htmlspecialchars($postPath);
                $redirectPath = dirname($postPath);
                $redirectPath = ($redirectPath === '.') ? '' : $redirectPath;
                header('Location: ?page=file&owner=' . urlencode($fOwner) . '&repo=' . urlencode($fRepo)
                    . '&path=' . urlencode($redirectPath) . '&ref=' . urlencode($postBranch ?: $fRef));
            } else {
                $errMsg = is_array($result) ? ($result['_msg'] ?? 'API error') : 'Koneksi gagal atau server tidak merespons';
                $_SESSION['flash_error'] = 'Gagal menghapus: ' . htmlspecialchars($errMsg);
                header('Location: ?page=file&owner=' . urlencode($fOwner) . '&repo=' . urlencode($fRepo)
                    . '&path=' . urlencode($postPath) . '&ref=' . urlencode($fRef));
            }
            exit;
        }

        // ── GET: new file form ──────────────────────────────────────
        if ($fAction === 'new') {
            $pageData['file_action'] = 'new';
            $pageData['file_owner']  = $fOwner;
            $pageData['file_repo']   = $fRepo;
            $pageData['file_path']   = $fPath; // directory prefix
            $pageData['file_ref']    = $fRef;
            $pageData['repo_info']   = $api->getRepo($fOwner, $fRepo);
            $pageData['branches']    = $api->getBranches($fOwner, $fRepo) ?? [];
            break;
        }

        // ── GET: load file info ─────────────────────────────────────
        $fileInfo = $fPath !== '' ? $api->getFileInfo($fOwner, $fRepo, $fPath, $fRef) : null;
        $pageData['file_info']   = $fileInfo;
        $pageData['file_action'] = $fAction; // '' = view/browse, 'edit' = editor
        $pageData['file_owner']  = $fOwner;
        $pageData['file_repo']   = $fRepo;
        $pageData['file_path']   = $fPath;
        $pageData['file_ref']    = $fRef;
        $pageData['repo_info']   = $api->getRepo($fOwner, $fRepo);
        $pageData['branches']    = $api->getBranches($fOwner, $fRepo) ?? [];

        // If it's a directory (array of items), store as tree
        if (is_array($fileInfo) && isset($fileInfo[0])) {
            // directory listing — sort dirs first then alpha, enrich with commits
            usort($fileInfo, function($a, $b) {
                $ta = ($a['type'] ?? 'file') === 'dir' ? 0 : 1;
                $tb = ($b['type'] ?? 'file') === 'dir' ? 0 : 1;
                return $ta !== $tb ? $ta - $tb : strcmp($a['name'] ?? '', $b['name'] ?? '');
            });
            $fileInfo = $api->enrichTreeWithCommits($fOwner, $fRepo, $fileInfo, $fRef, 30);
            $pageData['file_tree'] = $fileInfo;
            $pageData['file_type'] = 'dir';
        } elseif (is_array($fileInfo) && isset($fileInfo['type'])) {
            $pageData['file_type'] = $fileInfo['type']; // 'file' or 'dir' or 'symlink'
            if (($fileInfo['type'] ?? '') === 'file' && !empty($fileInfo['content'])) {
                $decoded = base64_decode(str_replace(["\n","\r"], '', $fileInfo['content']), true);
                $pageData['file_content'] = ($decoded !== false) ? $decoded : '';
            }
        } else {
            // Root path = directory listing
            if (is_array($fileInfo) && !empty($fileInfo)) {
                usort($fileInfo, function($a, $b) {
                    $ta = ($a['type'] ?? 'file') === 'dir' ? 0 : 1;
                    $tb = ($b['type'] ?? 'file') === 'dir' ? 0 : 1;
                    return $ta !== $tb ? $ta - $tb : strcmp($a['name'] ?? '', $b['name'] ?? '');
                });
                $fileInfo = $api->enrichTreeWithCommits($fOwner, $fRepo, $fileInfo, $fRef, 30);
            }
            $pageData['file_tree'] = $fileInfo ?? [];
            $pageData['file_type'] = 'dir';
        }
        break;
    case 'download':
        // ?page=download&type=archive&owner=X&repo=Y&ref=main&format=zip
        // ?page=download&type=release&url=ENCODED_URL&filename=NAME
        // ?page=download&type=raw&owner=X&repo=Y&path=FILE&ref=main
        $dlType = $_GET['type'] ?? 'archive';
        if ($dlType === 'archive') {
            $dlOwner  = trim($_GET['owner']  ?? '');
            $dlRepo   = trim($_GET['repo']   ?? '');
            $dlRef    = trim($_GET['ref']    ?? 'main');
            $dlFmt    = in_array($_GET['format'] ?? 'zip', ['zip','tar.gz']) ? ($_GET['format'] ?? 'zip') : 'zip';
            if ($dlOwner && $dlRepo) {
                $dlUrl  = $api->getArchiveUrl($dlOwner, $dlRepo, $dlRef, $dlFmt);
                $dlFile = "{$dlRepo}-{$dlRef}.{$dlFmt}";
                $api->proxyDownload($dlUrl, $dlFile);
                exit;
            }
        } elseif ($dlType === 'release') {
            $dlUrl  = urldecode($_GET['url']      ?? '');
            $dlFile = urldecode($_GET['filename'] ?? 'download');
            if ($dlUrl) {
                $api->proxyDownload($dlUrl, $dlFile);
                exit;
            }
        } elseif ($dlType === 'raw') {
            $dlOwner = trim($_GET['owner'] ?? '');
            $dlRepo  = trim($_GET['repo']  ?? '');
            $dlPath  = trim($_GET['path']  ?? '');
            $dlRef   = trim($_GET['ref']   ?? '');
            if ($dlOwner && $dlRepo && $dlPath) {
                $raw = $api->getRawFile($dlOwner, $dlRepo, $dlPath, $dlRef);
                if ($raw) {
                    $filename = basename($dlPath);
                    header('Content-Type: ' . ($raw['content_type'] ?: 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename) . '"');
                    header('Content-Length: ' . mb_strlen($raw['content'], '8bit'));
                    header('Cache-Control: no-store');
                    echo $raw['content'];
                    exit;
                }
            }
        }
        http_response_code(400);
        echo json_encode(['error' => 'Invalid download request']);
        exit;

    // ── Activity / live feed page ─────────────────────────────────
    case 'activity':
        $pageData['events'] = $api->getGlobalActivity(40);
        break;

    // ── JSON API endpoints (called by JS) ─────────────────────────
    case 'api':
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');
        $action = $_GET['action'] ?? '';
        if ($action === 'live_stats') {
            echo json_encode($api->getLiveStats());
            exit;
        }
        if ($action === 'activity') {
            $limit  = min((int)($_GET['limit'] ?? 30), 50);
            echo json_encode($api->getGlobalActivity($limit));
            exit;
        }
        if ($action === 'repo_stats') {
            $o = trim($_GET['owner'] ?? '');
            $r = trim($_GET['repo']  ?? '');
            if ($o && $r) {
                $repo = $api->getRepo($o, $r);
                echo json_encode($repo ? [
                    'stars'    => $repo['stars_count']    ?? 0,
                    'forks'    => $repo['forks_count']    ?? 0,
                    'watchers' => $repo['watchers_count'] ?? 0,
                    'issues'   => $repo['open_issues_count'] ?? 0,
                    'updated'  => $repo['updated_at']     ?? '',
                    'ts'       => time(),
                ] : ['error' => 'not found']);
                exit;
            }
        }
        if ($action === 'notifications') {
            $notifs = $api->getNotifications(20);
            echo json_encode($notifs ?: []);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;

    // ── SSE: Server-Sent Events stream ───────────────────────────
    case 'sse':
        // Long-polling SSE for real-time activity feed
        // Usage: new EventSource('?page=sse&type=activity')
        $sseType = $_GET['type'] ?? 'activity';
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        @ob_end_flush();

        $send = function(string $event, $data) {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data) . "\n\n";
            if (ob_get_length()) ob_flush();
            flush();
        };

        // Send initial heartbeat
        $send('heartbeat', ['ts' => time(), 'msg' => 'connected']);

        $maxIter = 6;   // max 6 pushes = ~30s then client reconnects
        $sleep   = 5;   // seconds between pushes

        for ($i = 0; $i < $maxIter; $i++) {
            if (connection_aborted()) break;

            if ($sseType === 'stats') {
                $send('stats', $api->getLiveStats());
            } elseif ($sseType === 'activity') {
                $events = $api->getGlobalActivity(15);
                $send('activity', $events);
            }

            if ($i < $maxIter - 1) {
                sleep($sleep);
            }
        }

        $send('end', ['ts' => time(), 'msg' => 'stream ended']);
        exit;
}

// ============================================================
// LOGIN PAGE — render & exit before main HTML
// ============================================================
if ($page === 'login'):
    $loginError   = $_SESSION['login_error'] ?? '';
    $loginMsg     = $_GET['msg'] ?? '';
    unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0d1117;
            color: #e6edf3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        /* Animated background grid */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(35,134,54,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(35,134,54,0.04) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }
        /* Glow blobs */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            animation: float 8s ease-in-out infinite;
        }
        .blob-1 { width: 500px; height: 500px; background: rgba(35,134,54,0.08); top: -150px; left: -150px; animation-delay: 0s; }
        .blob-2 { width: 400px; height: 400px; background: rgba(88,166,255,0.06); bottom: -100px; right: -100px; animation-delay: 3s; }
        .blob-3 { width: 300px; height: 300px; background: rgba(165,180,252,0.05); top: 40%; left: 50%; animation-delay: 6s; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50%       { transform: translate(20px, -20px) scale(1.05); }
        }

        .login-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 16px;
            padding: 40px 40px 36px;
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 10;
            box-shadow: 0 24px 64px rgba(0,0,0,0.5);
        }
        .logo-ring {
            width: 70px; height: 70px;
            background: linear-gradient(135deg, #16a34a 0%, #4ade80 100%);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 34px;
            margin: 0 auto 20px;
            box-shadow: 0 0 30px rgba(35,134,54,0.3);
            animation: pulse-logo 3s ease-in-out infinite;
        }
        @keyframes pulse-logo {
            0%, 100% { box-shadow: 0 0 30px rgba(35,134,54,0.3); }
            50%       { box-shadow: 0 0 50px rgba(35,134,54,0.6); }
        }
        h1 { font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 4px; }
        .subtitle { font-size: 13px; color: #8b949e; text-align: center; margin-bottom: 28px; }

        .form-label { display: block; font-size: 12px; font-weight: 600; color: #8b949e; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 6px; }
        .form-group { margin-bottom: 18px; }
        .input-wrap { position: relative; }
        .input-wrap .icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #484f58; font-size: 13px; pointer-events: none;
        }
        .form-input {
            width: 100%;
            background: #21262d;
            border: 1px solid #30363d;
            color: #e6edf3;
            border-radius: 8px;
            padding: 11px 12px 11px 36px;
            font-size: 14px;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }
        .form-input:focus { border-color: #238636; box-shadow: 0 0 0 3px rgba(35,134,54,.15); }
        .form-input::placeholder { color: #484f58; }

        /* Token input special */
        .token-input {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            letter-spacing: 0.5px;
            padding-right: 42px;
        }
        .toggle-vis {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #484f58; cursor: pointer;
            padding: 4px; font-size: 14px; transition: color .15s;
        }
        .toggle-vis:hover { color: #8b949e; }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #16a34a, #238636);
            border: none;
            color: white;
            border-radius: 8px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            letter-spacing: 0.3px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-login:hover { background: linear-gradient(135deg, #15803d, #16a34a); transform: translateY(-1px); box-shadow: 0 4px 20px rgba(35,134,54,0.4); }
        .btn-login:active { transform: translateY(0); }
        .btn-login:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        .alert { padding: 11px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 8px; }
        .alert-error   { background: rgba(248,81,73,.1);  border: 1px solid rgba(248,81,73,.3);  color: #f85149; }
        .alert-success { background: rgba(35,134,54,.1);  border: 1px solid rgba(35,134,54,.3);  color: #4ade80; }
        .alert-info    { background: rgba(88,166,255,.1); border: 1px solid rgba(88,166,255,.3); color: #58a6ff; }

        .divider { display: flex; align-items: center; gap: 10px; margin: 20px 0; color: #484f58; font-size: 12px; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #30363d; }

        .hint-box {
            background: #0d1117;
            border: 1px solid #21262d;
            border-radius: 8px;
            padding: 13px 14px;
            font-size: 12px;
            color: #8b949e;
            line-height: 1.7;
        }
        .hint-box a { color: #58a6ff; text-decoration: none; }
        .hint-box a:hover { text-decoration: underline; }
        .hint-box code { font-family: 'JetBrains Mono', monospace; background: #21262d; padding: 1px 5px; border-radius: 3px; font-size: 11px; color: #e6edf3; }

        .footer-note { text-align: center; font-size: 11px; color: #484f58; margin-top: 20px; }
        .footer-note a { color: #8b949e; text-decoration: none; }

        .badge-version { background: rgba(35,134,54,.15); border: 1px solid rgba(35,134,54,.3); color: #4ade80; font-size: 11px; padding: 2px 8px; border-radius: 20px; }

        /* Spinner */
        .spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: white; border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Advanced toggle */
        .advanced-btn { background: none; border: none; color: #58a6ff; font-size: 12px; cursor: pointer; padding: 0; text-decoration: underline; }
        #advanced-section { display: none; }
    </style>
</head>
<body>
    <!-- Blobs -->
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <div class="login-card">
        <!-- Logo -->
        <div class="logo-ring">🐱</div>
        <h1><?= h(APP_NAME) ?></h1>
        <p class="subtitle">
            Kelola ekosistem <strong style="color:#58a6ff;"><?= h(APP_DOMAIN) ?></strong>
            <span class="badge-version" style="margin-left: 6px;">v<?= APP_VERSION ?></span>
        </p>

        <!-- Alerts -->
        <?php if ($loginError): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-xmark" style="margin-top:1px;flex-shrink:0;"></i>
            <span><?= h($loginError) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($loginMsg === 'logged_out'): ?>
        <div class="alert alert-info">
            <i class="fas fa-circle-info" style="margin-top:1px;flex-shrink:0;"></i>
            <span>Anda telah berhasil logout.</span>
        </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" id="loginForm" onsubmit="handleSubmit(event)">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="gitea_url" id="hidden_url" value="<?= h(GITEA_BASE_URL) ?>">

            <div class="form-group">
                <label class="form-label" for="token">
                    <i class="fas fa-key" style="margin-right:5px;"></i>Access Token
                </label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon"></i>
                    <input
                        type="password"
                        id="token"
                        name="token"
                        class="form-input token-input"
                        placeholder="Masukkan Gitea access token..."
                        autocomplete="current-password"
                        spellcheck="false"
                        required
                    >
                    <button type="button" class="toggle-vis" onclick="toggleVis()" id="visBtn" title="Tampilkan / sembunyikan token">
                        <i class="fas fa-eye" id="visIcon"></i>
                    </button>
                </div>
                <div style="font-size:11px;color:#484f58;margin-top:5px;">
                    <i class="fas fa-shield-halved" style="margin-right:3px;"></i>Token disimpan di session browser, tidak di server.
                </div>
            </div>

            <!-- Advanced: custom URL -->
            <div style="margin-bottom:16px;">
                <button type="button" class="advanced-btn" onclick="toggleAdvanced()">
                    <i class="fas fa-sliders" style="margin-right:4px;"></i>Opsi Lanjutan
                </button>
                <div id="advanced-section" style="margin-top:12px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" for="gitea_url_input">Gitea Server URL</label>
                        <div class="input-wrap">
                            <i class="fas fa-server icon"></i>
                            <input type="url" id="gitea_url_input" class="form-input" value="<?= h(GITEA_BASE_URL) ?>"
                                   placeholder="https://git.sis1.dev" oninput="document.getElementById('hidden_url').value=this.value">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">
                <div class="spinner" id="spinner"></div>
                <i class="fas fa-right-to-bracket" id="loginIcon"></i>
                <span id="loginText">Masuk dengan Token</span>
            </button>
        </form>

        <div class="divider">Cara mendapatkan token</div>

        <div class="hint-box">
            <ol style="padding-left:16px;">
                <li>Login ke <a href="<?= h(GITEA_BASE_URL) ?>" target="_blank"><?= h(APP_DOMAIN) ?> <i class="fas fa-external-link-alt" style="font-size:9px;"></i></a></li>
                <li>Buka <strong>Settings</strong> → <strong>Applications</strong></li>
                <li>Buat token baru dengan permission: <code>repository</code> <code>user</code> <code>organization</code></li>
                <li>Salin token dan tempel di kolom di atas</li>
            </ol>
        </div>

        <p class="footer-note">
            <?= h(APP_NAME) ?> v<?= APP_VERSION ?> &nbsp;·&nbsp;
            <a href="<?= h(GITEA_BASE_URL) ?>" target="_blank"><?= h(APP_DOMAIN) ?></a>
        </p>
    </div>

    <script>
    function toggleVis() {
        const inp = document.getElementById('token');
        const ico = document.getElementById('visIcon');
        if (inp.type === 'password') {
            inp.type = 'text';
            ico.className = 'fas fa-eye-slash';
        } else {
            inp.type = 'password';
            ico.className = 'fas fa-eye';
        }
    }

    function toggleAdvanced() {
        const sec = document.getElementById('advanced-section');
        sec.style.display = sec.style.display === 'none' ? 'block' : 'none';
    }

    function handleSubmit(e) {
        const btn     = document.getElementById('loginBtn');
        const spinner = document.getElementById('spinner');
        const icon    = document.getElementById('loginIcon');
        const text    = document.getElementById('loginText');
        btn.disabled  = true;
        spinner.style.display = 'block';
        icon.style.display    = 'none';
        text.textContent      = 'Memverifikasi...';
    }

    // Auto-focus token field
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('token').focus();
    });
    </script>
</body>
</html>
<?php
// Stop here — don't render main app
exit;
endif; // end login page

?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?> — <?= h(APP_DOMAIN) ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Prism.js syntax highlighting (file viewer) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/themes/prism-tomorrow.min.css">
    <script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/prism.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/plugins/autoloader/prism-autoloader.min.js" defer></script>

    <!-- Monaco Editor (file editor) — loaded only on file edit/new pages -->
    <?php if (in_array($page, ['file']) && in_array($_GET['action'] ?? '', ['edit','new'])): ?>
    <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>
    <?php endif; ?>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], mono: ['JetBrains Mono', 'monospace'] },
                    colors: {
                        gitea: {
                            50:  '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0',
                            300: '#86efac', 400: '#4ade80', 500: '#22c55e',
                            600: '#16a34a', 700: '#15803d', 800: '#166534',
                            900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* ══════════════════════════════════════════════════════
           DESIGN TOKENS
        ══════════════════════════════════════════════════════ */
        :root {
            --bg-primary:    #0d1117;
            --bg-secondary:  #161b22;
            --bg-tertiary:   #21262d;
            --bg-hover:      #1c2128;
            --border-color:  #30363d;
            --border-subtle: #21262d;
            --text-primary:  #e6edf3;
            --text-secondary:#8b949e;
            --text-muted:    #484f58;
            --accent:        #238636;
            --accent-hover:  #2ea043;
            --accent-em:     #3fb950;
            --blue:          #58a6ff;
            --blue-dim:      rgba(88,166,255,.15);
            --orange:        #f0883e;
            --purple:        #bc8cff;
            --red:           #f85149;
            --yellow:        #e3b341;
            --green:         #3fb950;
            --sidebar-w:     256px;
            --topnav-h:      57px;
            --radius:        6px;
            --radius-lg:     12px;
            --shadow-sm:     0 1px 3px rgba(0,0,0,.4);
            --shadow-md:     0 4px 12px rgba(0,0,0,.5);
        }

        /* ══════════════════════════════════════════════════════
           RESET & BASE
        ══════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            line-height: 1.5;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }
        a { color: var(--blue); text-decoration: none; }
        a:hover { text-decoration: underline; }
        img { display: block; max-width: 100%; }
        button { font-family: inherit; cursor: pointer; }

        /* scrollbar */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        /* ══════════════════════════════════════════════════════
           TOP NAVIGATION BAR
        ══════════════════════════════════════════════════════ */
        .topnav {
            position: fixed; top: 0; left: 0; right: 0;
            height: var(--topnav-h);
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center;
            padding: 0 16px;
            gap: 8px;
            z-index: 100;
            backdrop-filter: blur(12px);
        }
        .topnav-brand {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none; color: var(--text-primary);
            flex-shrink: 0;
        }
        .topnav-brand:hover { text-decoration: none; }
        .brand-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg,#238636,#3fb950);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; flex-shrink: 0;
        }
        .brand-name { font-weight: 700; font-size: 15px; line-height:1; }
        .brand-domain { font-size: 11px; color: var(--text-muted); line-height:1; }

        /* search */
        .topnav-search {
            flex: 1; max-width: 480px;
            position: relative; margin: 0 12px;
        }
        .topnav-search input {
            width: 100%;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 7px 12px 7px 34px;
            font-size: 13px;
            color: var(--text-primary);
            transition: border-color .15s, box-shadow .15s;
        }
        .topnav-search input::placeholder { color: var(--text-muted); }
        .topnav-search input:focus { outline:none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(88,166,255,.1); }
        .topnav-search .search-icon {
            position: absolute; left:10px; top:50%; transform:translateY(-50%);
            color: var(--text-muted); font-size: 12px; pointer-events:none;
        }
        .topnav-right { display:flex; align-items:center; gap:6px; margin-left:auto; }

        /* nav links in topbar */
        .topnav-link {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 10px; border-radius: var(--radius);
            color: var(--text-secondary); font-size: 13px; font-weight:500;
            text-decoration: none; white-space: nowrap;
            transition: color .15s, background .15s;
        }
        .topnav-link:hover { color: var(--text-primary); background: var(--bg-tertiary); text-decoration:none; }
        .topnav-link.active { color: var(--text-primary); background: var(--bg-hover); }

        /* user avatar button */
        .nav-avatar {
            width: 30px; height: 30px; border-radius: 50%;
            border: 2px solid var(--border-color);
            cursor: pointer; object-fit: cover;
            transition: border-color .15s;
        }
        .nav-avatar:hover { border-color: var(--accent-em); }

        /* hamburger */
        .hamburger {
            display: none; background:none; border:none;
            color: var(--text-secondary); font-size:17px; padding:6px;
            border-radius: var(--radius);
        }
        .hamburger:hover { color: var(--text-primary); background: var(--bg-tertiary); }

        /* ══════════════════════════════════════════════════════
           SIDEBAR
        ══════════════════════════════════════════════════════ */
        .sidebar {
            position: fixed;
            top: var(--topnav-h); left: 0;
            width: var(--sidebar-w);
            height: calc(100vh - var(--topnav-h));
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            overflow-y: auto; overflow-x: hidden;
            z-index: 90;
            transition: transform .25s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-inner { padding: 12px 0 80px; }

        /* sidebar user card */
        .sb-user {
            margin: 0 10px 10px;
            padding: 12px;
            background: var(--bg-hover);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            display: flex; align-items: center; gap: 10px;
        }
        .sb-user-avatar { width:36px;height:36px;border-radius:50%;border:2px solid rgba(35,134,54,.4);flex-shrink:0; }
        .sb-user-name { font-weight:600;font-size:13px;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
        .sb-user-sub  { font-size:11px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
        .online-dot { width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 5px #22c55e;flex-shrink:0;margin-left:auto; }

        /* nav items */
        .nav-section {
            padding: 14px 16px 4px;
            font-size:10px; font-weight:700;
            color: var(--text-muted);
            text-transform: uppercase; letter-spacing:1px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px; margin: 1px 8px;
            border-radius: var(--radius);
            color: var(--text-secondary);
            font-size: 13px; font-weight: 500;
            text-decoration: none;
            transition: background .12s, color .12s;
            cursor: pointer;
        }
        .nav-item:hover { background: var(--bg-tertiary); color: var(--text-primary); text-decoration:none; }
        .nav-item.active {
            background: rgba(35,134,54,.15);
            color: var(--accent-em);
            font-weight: 600;
        }
        .nav-item .nav-icon { width:16px; text-align:center; font-size:13px; flex-shrink:0; }
        .nav-item .nav-badge {
            margin-left:auto; font-size:10px; font-weight:600;
            background:var(--bg-tertiary); border:1px solid var(--border-color);
            color:var(--text-muted); padding:1px 6px; border-radius:99px;
        }
        .nav-item .ext-icon { margin-left:auto;font-size:9px;opacity:.4; }

        .nav-divider { height:1px; background:var(--border-color); margin:8px 10px; }

        /* ══════════════════════════════════════════════════════
           MAIN LAYOUT
        ══════════════════════════════════════════════════════ */
        .app-shell {
            display: flex;
            padding-top: var(--topnav-h);
            min-height: 100vh;
        }
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-w);
            min-width: 0;
        }
        .page-content {
            padding: 24px;
            max-width: 1280px;
        }

        /* page header bar */
        .page-header {
            border-bottom: 1px solid var(--border-color);
            padding: 14px 24px;
            background: var(--bg-secondary);
            display: flex; align-items: center;
            justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
            position: sticky; top: var(--topnav-h);
            z-index: 40;
        }
        .page-header h1 {
            font-size: 18px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 10px;
        }
        .page-header p { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        /* breadcrumb */
        .breadcrumb { display:flex; align-items:center; gap:6px; font-size:13px; }
        .breadcrumb a { color:var(--text-secondary); text-decoration:none; }
        .breadcrumb a:hover { color:var(--text-primary); }
        .breadcrumb .sep { color:var(--text-muted); }

        /* ══════════════════════════════════════════════════════
           CARDS & SURFACES
        ══════════════════════════════════════════════════════ */
        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .card-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center;
            justify-content: space-between;
            font-weight: 600; font-size: 14px;
            background: var(--bg-hover);
        }
        .card-body { padding: 18px; }
        .card-footer {
            padding: 12px 18px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-hover);
            font-size: 12px; color: var(--text-muted);
        }

        /* ══════════════════════════════════════════════════════
           STAT CARDS
        ══════════════════════════════════════════════════════ */
        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px 18px;
            display: flex; flex-direction: column;
            gap: 6px;
            transition: border-color .15s, transform .15s;
            position: relative; overflow: hidden;
        }
        .stat-card::before {
            content:''; position:absolute; inset:0;
            background:linear-gradient(135deg,rgba(255,255,255,.02),transparent);
            pointer-events:none;
        }
        .stat-card:hover { border-color:var(--border-color); transform:translateY(-2px); box-shadow:var(--shadow-md); }
        .stat-icon { font-size:22px; margin-bottom:4px; }
        .stat-value { font-size:26px; font-weight:800; color:var(--text-primary); line-height:1; }
        .stat-label { font-size:12px; color:var(--text-secondary); font-weight:500; }
        .stat-trend { font-size:11px; margin-top:2px; }
        .grid-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }

        /* ══════════════════════════════════════════════════════
           REPO CARDS
        ══════════════════════════════════════════════════════ */
        .repo-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px;
            display: flex; flex-direction: column;
            gap: 10px;
            transition: border-color .15s, box-shadow .15s;
        }
        .repo-card:hover { border-color: rgba(88,166,255,.4); box-shadow: 0 0 0 1px rgba(88,166,255,.1); }
        .repo-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; }
        .repo-card-name {
            display:flex; align-items:center; gap:8px; flex:1; min-width:0;
        }
        .repo-card-name img { width:20px;height:20px;border-radius:4px;flex-shrink:0; }
        .repo-name {
            font-weight:600; font-size:14px; color:var(--blue);
            text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .repo-name:hover { text-decoration:underline; }
        .repo-desc {
            font-size:13px; color:var(--text-secondary); line-height:1.5;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
        }
        .repo-meta {
            display:flex; flex-wrap:wrap; gap:12px;
            font-size:12px; color:var(--text-secondary);
            align-items:center; margin-top:auto;
        }
        .repo-meta span { display:flex; align-items:center; gap:4px; }
        .lang-dot { display:inline-block; width:10px;height:10px;border-radius:50%; flex-shrink:0; }
        .repo-card-actions { display:flex; gap:6px; padding-top:10px; border-top:1px solid var(--border-subtle); }

        /* repo list row */
        .repo-row { border-bottom:1px solid var(--border-color); }
        .repo-row:last-child { border-bottom:none; }
        .repo-row td { padding:12px 16px; }
        .repo-row:hover td { background:var(--bg-hover); }

        /* grid */
        .grid-repos { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:12px; }
        .grid-users { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }

        /* ══════════════════════════════════════════════════════
           BADGES & LABELS
        ══════════════════════════════════════════════════════ */
        .badge {
            display:inline-flex; align-items:center; gap:4px;
            padding:2px 8px; border-radius:99px; font-size:11px; font-weight:500;
            white-space:nowrap;
        }
        .badge-public   { background:rgba(63,185,80,.12);  color:var(--green);  border:1px solid rgba(63,185,80,.3); }
        .badge-private  { background:rgba(188,140,255,.12);color:var(--purple); border:1px solid rgba(188,140,255,.3); }
        .badge-fork     { background:rgba(88,166,255,.12); color:var(--blue);   border:1px solid rgba(88,166,255,.3); }
        .badge-archived { background:rgba(139,148,158,.12);color:var(--text-secondary);border:1px solid var(--border-color); }
        .badge-admin    { background:rgba(240,136,62,.12); color:var(--orange); border:1px solid rgba(240,136,62,.3); }
        .badge-topic {
            background:rgba(88,166,255,.08); color:var(--blue);
            border:1px solid rgba(88,166,255,.2);
            padding:2px 9px; border-radius:99px; font-size:11px;
            text-decoration:none;
        }
        .badge-topic:hover { background:rgba(88,166,255,.18); text-decoration:none; }

        /* ══════════════════════════════════════════════════════
           BUTTONS
        ══════════════════════════════════════════════════════ */
        .btn {
            display:inline-flex; align-items:center; gap:6px;
            padding:6px 14px; border-radius:var(--radius);
            font-size:13px; font-weight:500;
            border:1px solid; cursor:pointer;
            text-decoration:none; white-space:nowrap;
            transition:all .15s; line-height:1.4;
        }
        .btn:hover { text-decoration:none; }
        .btn-sm { padding:4px 10px; font-size:12px; }
        .btn-lg { padding:9px 20px; font-size:14px; }
        .btn-icon { padding:6px 8px; }
        .btn-primary  { background:var(--accent);   border-color:var(--accent);    color:#fff; }
        .btn-primary:hover  { background:var(--accent-hover); }
        .btn-secondary{ background:var(--bg-tertiary); border-color:var(--border-color); color:var(--text-primary); }
        .btn-secondary:hover{ border-color:var(--text-secondary); }
        .btn-ghost    { background:transparent; border-color:transparent; color:var(--text-secondary); }
        .btn-ghost:hover    { background:var(--bg-tertiary); color:var(--text-primary); }
        .btn-blue     { background:var(--blue-dim); border-color:rgba(88,166,255,.3); color:var(--blue); }
        .btn-blue:hover     { background:rgba(88,166,255,.25); }
        .btn-danger   { background:rgba(248,81,73,.12); border-color:rgba(248,81,73,.3); color:var(--red); }
        .btn-danger:hover   { background:rgba(248,81,73,.22); }
        .btn-green    { background:rgba(63,185,80,.12);  border-color:rgba(63,185,80,.3); color:var(--green); }
        .btn-green:hover    { background:rgba(63,185,80,.22); }

        /* ══════════════════════════════════════════════════════
           FORMS
        ══════════════════════════════════════════════════════ */
        .form-group { margin-bottom:16px; }
        .form-label { display:block; font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:6px; }
        .form-input {
            width:100%; background:var(--bg-tertiary);
            border:1px solid var(--border-color);
            color:var(--text-primary); border-radius:var(--radius);
            padding:8px 12px; font-size:14px; font-family:inherit;
            transition:border-color .15s, box-shadow .15s;
        }
        .form-input:focus { outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(88,166,255,.1); }
        .form-input::placeholder { color:var(--text-muted); }
        select.form-input { cursor:pointer; }
        .form-hint { font-size:12px; color:var(--text-muted); margin-top:4px; }

        /* ══════════════════════════════════════════════════════
           ALERTS
        ══════════════════════════════════════════════════════ */
        .alert {
            padding:12px 16px; border-radius:var(--radius);
            font-size:13px; display:flex; align-items:flex-start; gap:10px;
            margin-bottom:16px;
        }
        .alert-success { background:rgba(63,185,80,.08);  border:1px solid rgba(63,185,80,.25);  color:var(--green); }
        .alert-warning { background:rgba(227,179,65,.08); border:1px solid rgba(227,179,65,.25); color:var(--yellow); }
        .alert-info    { background:rgba(88,166,255,.08); border:1px solid rgba(88,166,255,.25); color:var(--blue); }
        .alert-error,
        .alert-danger  { background:rgba(248,81,73,.08);  border:1px solid rgba(248,81,73,.25);  color:var(--red); }

        /* ══════════════════════════════════════════════════════
           TABS
        ══════════════════════════════════════════════════════ */
        .tabs {
            display:flex; gap:0;
            border-bottom:1px solid var(--border-color);
            overflow-x:auto; scrollbar-width:none;
        }
        .tabs::-webkit-scrollbar { display:none; }
        .tab-item {
            display:flex; align-items:center; gap:7px;
            padding:10px 16px; font-size:13px; font-weight:500;
            color:var(--text-secondary); text-decoration:none;
            border-bottom:2px solid transparent; margin-bottom:-1px;
            white-space:nowrap; transition:color .15s;
        }
        .tab-item:hover { color:var(--text-primary); text-decoration:none; }
        .tab-item.active { color:var(--text-primary); border-bottom-color:var(--orange); }
        .tab-count {
            background:var(--bg-tertiary); border:1px solid var(--border-color);
            color:var(--text-muted); font-size:11px; padding:1px 6px;
            border-radius:99px; font-weight:600;
        }

        /* ══════════════════════════════════════════════════════
           TABLE
        ══════════════════════════════════════════════════════ */
        .data-table { width:100%; border-collapse:collapse; font-size:13px; }
        .data-table th {
            padding:10px 16px; text-align:left;
            font-size:11px; font-weight:700; color:var(--text-muted);
            text-transform:uppercase; letter-spacing:.6px;
            border-bottom:1px solid var(--border-color);
            background:var(--bg-hover);
        }
        .data-table td { padding:12px 16px; border-bottom:1px solid var(--border-subtle); color:var(--text-secondary); vertical-align:middle; }
        .data-table tr:last-child td { border-bottom:none; }
        .data-table tr:hover td { background:var(--bg-hover); }

        /* ══════════════════════════════════════════════════════
           CODE / MONO
        ══════════════════════════════════════════════════════ */
        .clone-box {
            background:var(--bg-primary); border:1px solid var(--border-color);
            border-radius:var(--radius); padding:10px 14px;
            font-family:'JetBrains Mono',monospace; font-size:12px;
            color:var(--text-secondary);
            display:flex; align-items:center; gap:8px;
        }
        .clone-box code { flex:1; color:var(--blue); word-break:break-all; }
        .commit-sha {
            font-family:'JetBrains Mono',monospace; font-size:11px;
            color:var(--blue); background:rgba(88,166,255,.1);
            padding:2px 7px; border-radius:4px; white-space:nowrap;
        }

        /* ══════════════════════════════════════════════════════
           LANGUAGE BAR
        ══════════════════════════════════════════════════════ */
        .lang-bar { height:8px; border-radius:99px; overflow:hidden; display:flex; background:var(--bg-tertiary); }
        .lang-segment { height:100%; transition:width .4s ease; }

        /* ══════════════════════════════════════════════════════
           USER / ORG CARDS
        ══════════════════════════════════════════════════════ */
        .user-card {
            background:var(--bg-secondary); border:1px solid var(--border-color);
            border-radius:var(--radius-lg); padding:20px 16px;
            text-align:center; transition:border-color .15s, box-shadow .15s;
            display:flex; flex-direction:column; align-items:center; gap:8px;
        }
        .user-card:hover { border-color:rgba(88,166,255,.4); box-shadow:var(--shadow-sm); }
        .user-avatar {
            width:64px; height:64px; border-radius:50%;
            border:3px solid var(--border-color); object-fit:cover;
        }
        .avatar-placeholder {
            display:flex; align-items:center; justify-content:center;
            background:linear-gradient(135deg,#1a4a2e,#2ea043);
            color:var(--green); font-weight:800; border-radius:50%;
        }

        /* ══════════════════════════════════════════════════════
           MISC COMPONENTS
        ══════════════════════════════════════════════════════ */
        /* empty state */
        .empty-state { text-align:center; padding:56px 24px; }
        .empty-state .es-icon { font-size:52px; margin-bottom:16px; opacity:.3; }
        .empty-state h3 { font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:8px; }
        .empty-state p  { font-size:13px; color:var(--text-secondary); max-width:360px; margin:0 auto; }

        /* spinner */
        .spinner { display:inline-block; width:18px;height:18px; border:2px solid var(--border-color); border-top-color:var(--blue); border-radius:50%; animation:spin .6s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* copy btn */
        .copy-btn { background:var(--bg-tertiary); border:1px solid var(--border-color); color:var(--text-muted); padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px; transition:all .15s; white-space:nowrap; }
        .copy-btn:hover { border-color:var(--blue); color:var(--blue); }
        .copy-btn.copied { border-color:var(--accent); color:var(--green); }

        /* progress */
        .progress-bar { background:var(--bg-tertiary); border-radius:99px; height:6px; overflow:hidden; }
        .progress-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,#238636,#3fb950); transition:width .5s ease; }

        /* tooltip */
        [data-tip] { position:relative; }
        [data-tip]:hover::after {
            content:attr(data-tip); position:absolute; bottom:calc(100% + 4px); left:50%; transform:translateX(-50%);
            background:var(--bg-tertiary); border:1px solid var(--border-color); color:var(--text-primary);
            font-size:11px; padding:4px 8px; border-radius:4px; white-space:nowrap; z-index:200; pointer-events:none;
        }

        /* version badge */
        .version-badge { background:rgba(35,134,54,.12); border:1px solid rgba(35,134,54,.3); color:var(--green); font-size:11px; padding:2px 8px; border-radius:99px; }

        /* commit item */
        .commit-item { padding:12px 0; border-bottom:1px solid var(--border-subtle); display:flex; align-items:flex-start; gap:12px; }
        .commit-item:last-child { border-bottom:none; }

        /* activity dot */
        .activity-dot { width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:5px; }

        /* search highlight */
        mark { background:rgba(227,179,65,.25); color:inherit; border-radius:2px; padding:0 2px; }

        /* ══════════════════════════════════════════════════════
           RESPONSIVE — TABLET  ≤ 1024px
        ══════════════════════════════════════════════════════ */
        @media (max-width: 1024px) {
            :root { --sidebar-w: 220px; }
            .topnav-search { max-width: 300px; }
        }

        /* ══════════════════════════════════════════════════════
           RESPONSIVE — MOBILE  ≤ 768px
        ══════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            :root { --sidebar-w: 260px; }
            .sidebar {
                transform: translateX(-100%);
                top: 0; height: 100vh;
                box-shadow: var(--shadow-md);
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .hamburger { display: flex; }
            .topnav-search { display: none; }
            .topnav-link.hide-mobile { display: none; }
            .page-content { padding: 16px; }
            .grid-repos { grid-template-columns: 1fr; }
            .grid-stats { grid-template-columns: repeat(2,1fr); }
            .grid-users { grid-template-columns: repeat(2,1fr); }
            .page-header { padding: 12px 16px; }
            /* Bottom navigation bar */
            .mobile-bottom-nav {
                display: flex !important;
                position: fixed; bottom:0; left:0; right:0;
                height: 56px;
                background: var(--bg-secondary);
                border-top: 1px solid var(--border-color);
                z-index: 95;
                padding: 0 4px;
            }
            .main-content { padding-bottom: 56px; }
        }
        @media (min-width: 769px) {
            .mobile-bottom-nav { display: none !important; }
        }

        /* bottom nav items */
        .mobile-bottom-nav {
            display: none;
            align-items: center; justify-content: space-around;
        }
        .bottom-nav-item {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 3px;
            flex: 1; padding: 8px 4px;
            color: var(--text-secondary); text-decoration: none;
            font-size: 10px; font-weight: 500;
            border-radius: var(--radius); transition: color .15s;
        }
        .bottom-nav-item i { font-size: 18px; }
        .bottom-nav-item.active { color: var(--green); }
        .bottom-nav-item:hover { color: var(--text-primary); text-decoration: none; }

        /* ══════════════════════════════════════════════════════
           PAGE-SPECIFIC
        ══════════════════════════════════════════════════════ */
        /* dashboard hero */
        .dash-hero {
            background: linear-gradient(135deg,#0d2818 0%,#162a1e 50%,#0d1117 100%);
            border: 1px solid rgba(35,134,54,.25);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 20px;
            position: relative; overflow: hidden;
        }
        .dash-hero::after {
            content: '🐱'; font-size: 120px; opacity: .04;
            position: absolute; right: -10px; top: -20px;
            pointer-events: none;
        }

        /* repo detail header */
        .repo-header {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 16px;
        }

        /* sidebar in repo detail  */
        .repo-sidebar-widget {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 12px;
        }
        .repo-sidebar-widget .w-header {
            padding: 10px 14px;
            font-size:12px; font-weight:700;
            color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px;
            border-bottom:1px solid var(--border-color);
            background: var(--bg-hover);
        }
        .repo-sidebar-widget .w-body { padding: 12px 14px; }

        /* contributor row */
        .contrib-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border-subtle); }
        .contrib-row:last-child { border-bottom:none; }

        /* ══════════════════════════════════════════════════════
           RESPONSIVE GRIDS (dashboard, repo-detail, user-profile)
        ══════════════════════════════════════════════════════ */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .repo-detail-grid {
            display: grid;
            grid-template-columns: 3fr 1fr;
            gap: 20px;
            align-items: start;
        }
        .user-profile-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 24px;
            align-items: start;
        }
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .stat-pill {
            display: flex; align-items: center; gap: 6px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 8px 14px; font-size: 14px;
        }
        .stat-pill strong { color: var(--text-primary); }
        .stat-pill span   { color: var(--text-muted); font-size: 13px; }

        /* page title block */
        .page-title { margin-bottom: 20px; }
        .page-title h1 { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
        .page-title p  { font-size: 13px; color: var(--text-muted); margin: 0; }
        .page-title-row {
            display: flex; align-items: flex-start;
            justify-content: space-between; flex-wrap: wrap; gap: 12px;
            margin-bottom: 20px;
        }

        /* info row (key/value) */
        .info-row {
            display: flex; justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-subtle);
            font-size: 13px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .key   { color: var(--text-muted); font-size: 12px; }
        .info-row .value { color: var(--text-primary); font-weight: 500; }

        /* lang list item */
        .lang-item {
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 5px 0;
        }
        .lang-item-left { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .lang-item-right { font-size: 12px; color: var(--text-muted); }

        /* branch list */
        .branch-item {
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 7px 10px; border-radius: var(--radius);
            transition: background .12s;
        }
        .branch-item:hover { background: var(--bg-tertiary); }

        /* release item */
        .release-item {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .release-item:last-child { border-bottom: none; }

        /* tag chip */
        .tag-chip {
            font-family: 'JetBrains Mono', monospace; font-size: 12px;
            color: var(--purple);
            background: rgba(188,140,255,.08);
            border: 1px solid rgba(188,140,255,.2);
            padding: 3px 10px; border-radius: var(--radius);
            text-decoration: none;
        }
        .tag-chip:hover { background: rgba(188,140,255,.18); text-decoration: none; }

        /* hero section */
        .dash-hero-inner {
            display: flex; align-items: center;
            justify-content: space-between;
            flex-wrap: wrap; gap: 16px;
        }
        .dash-hero-title {
            font-size: 22px; font-weight: 700;
            color: var(--text-primary); margin: 0 0 6px;
        }
        .dash-hero-sub { color: var(--text-secondary); font-size: 14px; margin: 0; }

        /* sidebar right column stack */
        .right-col { display: flex; flex-direction: column; gap: 14px; }

        /* quick actions list */
        .quick-actions { display: flex; flex-direction: column; gap: 8px; padding: 14px; }

        /* ══════════════════════════════════════════════════════
           MOBILE RESPONSIVE OVERRIDES
        ══════════════════════════════════════════════════════ */
        @media (max-width: 900px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .repo-detail-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .user-profile-grid { grid-template-columns: 1fr; }
            .user-profile-grid > div:first-child {
                display: flex; align-items: center; gap: 16px;
                flex-wrap: wrap;
            }
            .user-profile-grid > div:first-child img,
            .user-profile-grid > div:first-child .avatar-placeholder {
                width: 72px; height: 72px; max-width: 72px; border-radius: 50%;
            }
            .stats-row { gap: 6px; }
            .stat-pill { padding: 6px 10px; font-size: 13px; }
        }
        @media (max-width: 480px) {
            .grid-stats { grid-template-columns: repeat(2,1fr); }
            .page-title-row { flex-direction: column; }
            .page-title-row .btn { width: 100%; justify-content: center; }
        }

        /* ══════════════════════════════════════════════════════
           ANIMATIONS
        ══════════════════════════════════════════════════════ */
        @keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
        .fade-in { animation: fadeIn .2s ease forwards; }

        @keyframes slideIn { from { opacity:0; transform:translateX(-8px); } to { opacity:1; transform:translateX(0); } }
        .slide-in { animation: slideIn .2s ease forwards; }
    </style>
</head>
<body>

<!-- ════════════════════════════════════════════════════════════
     TOP NAVIGATION
════════════════════════════════════════════════════════════ -->
<nav class="topnav">
    <!-- Hamburger (mobile) -->
    <button class="hamburger" id="hamburger" onclick="toggleSidebar()" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Brand -->
    <a href="?page=dashboard" class="topnav-brand">
        <div class="brand-icon">🐱</div>
        <div class="d-flex flex-col" style="line-height:1.2;">
            <span class="brand-name"><?= h(APP_NAME) ?></span>
            <span class="brand-domain"><?= h(APP_DOMAIN) ?></span>
        </div>
    </a>

    <!-- Global search -->
    <form method="GET" action="" class="topnav-search" style="display:flex;">
        <input type="hidden" name="page" value="repos">
        <i class="fas fa-search search-icon"></i>
        <input type="text" name="q" value="<?= h($currentSearch) ?>" placeholder="Search repositories…" autocomplete="off">
    </form>

    <!-- Top nav links (desktop) -->
    <div class="topnav-right">
        <a href="?page=repos" class="topnav-link hide-mobile <?= $page==='repos'?'active':'' ?>">
            <i class="fas fa-code-branch"></i><span>Repos</span>
        </a>
        <a href="?page=users" class="topnav-link hide-mobile <?= $page==='users'?'active':'' ?>">
            <i class="fas fa-users"></i><span>Users</span>
        </a>
        <a href="?page=orgs" class="topnav-link hide-mobile <?= $page==='orgs'?'active':'' ?>">
            <i class="fas fa-building"></i><span>Orgs</span>
        </a>

        <!-- Open Gitea -->
        <a href="<?= h($giteaUrl) ?>" target="_blank" class="btn btn-secondary btn-sm" style="gap:5px;">
            <i class="fas fa-external-link-alt" style="font-size:11px;"></i>
            <span class="hide-mobile">Gitea</span>
        </a>

        <!-- User avatar -->
        <?php if (!empty($currentUser['avatar_url'])): ?>
        <a href="?page=settings" title="Settings">
            <img src="<?= h($currentUser['avatar_url']) ?>" class="nav-avatar" alt="">
        </a>
        <?php else: ?>
        <a href="?page=settings" class="btn btn-icon btn-ghost" title="Settings">
            <i class="fas fa-user-circle" style="font-size:20px;color:var(--text-secondary);"></i>
        </a>
        <?php endif; ?>
    </div>
</nav>

<!-- ════════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">

        <!-- User profile card -->
        <div class="sb-user">
            <?php if (!empty($currentUser['avatar_url'])): ?>
            <img src="<?= h($currentUser['avatar_url']) ?>" class="sb-user-avatar" alt="">
            <?php else: ?>
            <div class="avatar-placeholder sb-user-avatar" style="font-size:15px;">
                <?= strtoupper(substr($currentUser['login']??'U',0,1)) ?>
            </div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div class="sb-user-name">
                    <?= h($currentUser['login'] ?? 'User') ?>
                    <?php if ($currentUser['is_admin']??false): ?>
                    <span class="badge badge-admin" style="font-size:9px;padding:1px 5px;margin-left:4px;">ADMIN</span>
                    <?php endif; ?>
                </div>
                <div class="sb-user-sub"><?= h($currentUser['email'] ?? $currentUser['full_name'] ?? 'Authenticated') ?></div>
            </div>
            <div class="online-dot" title="Online"></div>
        </div>

        <!-- Navigation -->
        <nav>
            <div class="nav-section">Navigate</div>
            <a href="?page=dashboard" class="nav-item <?= $page==='dashboard'?'active':'' ?>">
                <span class="nav-icon"><i class="fas fa-home"></i></span>
                Dashboard
            </a>
            <a href="?page=repos" class="nav-item <?= in_array($page,['repos','repo'])?'active':'' ?>">
                <span class="nav-icon"><i class="fas fa-code-branch"></i></span>
                Repositories
                <span class="nav-badge"><?= $pageData['total_repos'] ?? '…' ?></span>
            </a>
            <a href="?page=users" class="nav-item <?= in_array($page,['users','user'])?'active':'' ?>">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                Users
            </a>
            <a href="?page=orgs" class="nav-item <?= in_array($page,['orgs','org'])?'active':'' ?>">
                <span class="nav-icon"><i class="fas fa-building"></i></span>
                Organizations
            </a>
            <a href="?page=activity" class="nav-item <?= $page==='activity'?'active':'' ?>">
                <span class="nav-icon"><i class="fas fa-bolt"></i></span>
                Activity
                <span id="navLiveDot" style="width:7px;height:7px;border-radius:50%;background:var(--green);margin-left:6px;display:inline-block;animation:livePulse 1.4s ease-in-out infinite;opacity:.85;" title="Live"></span>
            </a>

            <div class="nav-divider"></div>
            <div class="nav-section">Quick Links</div>
            <a href="<?= h(giteaLink('explore/repos')) ?>" target="_blank" class="nav-item">
                <span class="nav-icon"><i class="fas fa-compass"></i></span>
                Explore
                <span class="ext-icon"><i class="fas fa-external-link-alt"></i></span>
            </a>
            <a href="<?= h(giteaLink('issues')) ?>" target="_blank" class="nav-item">
                <span class="nav-icon"><i class="fas fa-circle-dot"></i></span>
                Issues
                <span class="ext-icon"><i class="fas fa-external-link-alt"></i></span>
            </a>
            <a href="<?= h(giteaLink('pulls')) ?>" target="_blank" class="nav-item">
                <span class="nav-icon"><i class="fas fa-code-pull-request"></i></span>
                Pull Requests
                <span class="ext-icon"><i class="fas fa-external-link-alt"></i></span>
            </a>

            <div class="nav-divider"></div>
            <div class="nav-section">Account</div>
            <a href="<?= h(giteaLink($currentUser['login']??'')) ?>" target="_blank" class="nav-item">
                <span class="nav-icon"><i class="fas fa-user"></i></span>
                My Profile
                <span class="ext-icon"><i class="fas fa-external-link-alt"></i></span>
            </a>
            <a href="?page=settings" class="nav-item <?= $page==='settings'?'active':'' ?>">
                <span class="nav-icon"><i class="fas fa-sliders"></i></span>
                Settings
            </a>

            <!-- Session info -->
            <?php if (!empty($_SESSION['login_time'])): ?>
            <div style="margin:8px 10px;padding:8px 10px;background:var(--bg-hover);border:1px solid var(--border-color);border-radius:var(--radius);font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:6px;">
                <i class="fas fa-clock" style="font-size:10px;"></i>
                Logged in <?= timeAgo(date('c',$_SESSION['login_time'])) ?>
                <?php if ($siteVersion): ?>
                <span class="version-badge" style="margin-left:auto;"><?= h($siteVersion['version']??'') ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Logout -->
            <form method="POST" style="margin:4px 8px 0;">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="nav-item" style="width:100%;background:none;border:none;color:#f87171;">
                    <span class="nav-icon"><i class="fas fa-right-from-bracket"></i></span>
                    Logout
                </button>
            </form>
        </nav>
    </div>
</aside>

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:89;backdrop-filter:blur(2px);"></div>

<!-- Bottom navigation (mobile only) -->
<nav class="mobile-bottom-nav">
    <a href="?page=dashboard" class="bottom-nav-item <?= $page==='dashboard'?'active':'' ?>">
        <i class="fas fa-home"></i><span>Home</span>
    </a>
    <a href="?page=repos" class="bottom-nav-item <?= in_array($page,['repos','repo'])?'active':'' ?>">
        <i class="fas fa-code-branch"></i><span>Repos</span>
    </a>
    <a href="?page=activity" class="bottom-nav-item <?= $page==='activity'?'active':'' ?>" style="position:relative;">
        <i class="fas fa-bolt"></i>
        <span style="position:absolute;top:6px;right:18px;width:6px;height:6px;border-radius:50%;background:var(--green);animation:livePulse 1.4s ease-in-out infinite;"></span>
        <span>Live</span>
    </a>
    <a href="?page=users" class="bottom-nav-item <?= $page==='users'?'active':'' ?>">
        <i class="fas fa-users"></i><span>Users</span>
    </a>
    <a href="?page=settings" class="bottom-nav-item <?= $page==='settings'?'active':'' ?>">
        <i class="fas fa-sliders"></i><span>Settings</span>
    </a>
</nav>

<!-- ════════════════════════════════════════════════════════════
     APP SHELL
════════════════════════════════════════════════════════════ -->
<div class="app-shell">
<div class="main-content">

    <!-- Page Content -->
    <main class="page-content fade-in">

        <?php if ($msg === 'cache_cleared'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> Cache cleared successfully!</div>
        <?php endif; ?>

        <?php if (!$siteVersion && $page !== 'settings'): ?>
        <div class="alert alert-warning">
            <i class="fas fa-triangle-exclamation"></i> 
            Tidak dapat terhubung ke <strong><?= h($giteaUrl) ?></strong>.
            Server mungkin sedang down. 
            <a href="?page=settings" style="color: inherit; font-weight: 600; text-decoration: underline;">Lihat Settings →</a>
        </div>
        <?php endif; ?>

        <!-- ====================================================
             DASHBOARD
             ==================================================== -->
        <?php if ($page === 'dashboard'): ?>
        <?php
        $repos = $pageData['repos'] ?? [];
        $allReposForStats = $pageData['all_repos'] ?? $repos;
        $totalRepos = $pageData['total_repos'] ?? 0;
        $publicCount = 0; $privateCount = 0; $forkCount = 0; $archivedCount = 0;
        $totalStars = 0; $totalForks = 0; $totalIssues = 0;
        $languages = [];
        foreach ($allReposForStats as $r) {
            if ($r['private']) $privateCount++; else $publicCount++;
            if ($r['fork']) $forkCount++;
            if ($r['archived'] ?? false) $archivedCount++;
            $totalStars  += $r['stars_count'] ?? 0;
            $totalForks  += $r['forks_count'] ?? 0;
            $totalIssues += $r['open_issues_count'] ?? 0;
            if (!empty($r['language'])) {
                $languages[$r['language']] = ($languages[$r['language']] ?? 0) + 1;
            }
        }
        arsort($languages);
        $topLangs = array_slice($languages, 0, 8, true);
        ?>

        <!-- ── Hero Banner ───────────────────────────────────────── -->
        <div class="dash-hero">
            <div class="dash-hero-inner">
                <div>
                    <h1 class="dash-hero-title">
                        Welcome back, <span style="color:var(--green);"><?= h($currentUser['login'] ?? 'User') ?></span> 👋
                    </h1>
                    <p class="dash-hero-sub">
                        Managing <strong style="color:var(--text-primary);"><?= formatNumber($totalRepos) ?> repositories</strong>
                        at <a href="<?= h($giteaUrl) ?>" target="_blank"><?= h(APP_DOMAIN) ?></a>
                        <?php if ($siteVersion): ?>
                        &nbsp;·&nbsp; Gitea <span class="version-badge"><?= h($siteVersion['version'] ?? '') ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="?page=repos" class="btn btn-primary"><i class="fas fa-code-branch"></i> Browse Repos</a>
                    <a href="<?= h(giteaLink('repo/create')) ?>" target="_blank" class="btn btn-secondary"><i class="fas fa-plus"></i> New Repo</a>
                </div>
            </div>
        </div>

        <!-- ── Stats Grid ────────────────────────────────────────── -->
        <div class="grid-stats" style="margin-bottom:24px;">
            <div class="stat-card">
                <div class="stat-icon" style="color:var(--blue);font-size:18px;"><i class="fas fa-code-branch"></i></div>
                <div class="stat-value" style="color:var(--blue);"><?= formatNumber($totalRepos) ?></div>
                <div class="stat-label">Total Repos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color:var(--green);font-size:18px;"><i class="fas fa-globe"></i></div>
                <div class="stat-value" style="color:var(--green);"><?= $publicCount ?></div>
                <div class="stat-label">Public</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color:var(--purple);font-size:18px;"><i class="fas fa-lock"></i></div>
                <div class="stat-value" style="color:var(--purple);"><?= $privateCount ?></div>
                <div class="stat-label">Private</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color:var(--yellow);font-size:18px;"><i class="fas fa-star"></i></div>
                <div class="stat-value" style="color:var(--yellow);"><?= formatNumber($totalStars) ?></div>
                <div class="stat-label">Stars</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color:var(--orange);font-size:18px;"><i class="fas fa-code-fork"></i></div>
                <div class="stat-value" style="color:var(--orange);"><?= formatNumber($totalForks) ?></div>
                <div class="stat-label">Forks</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color:var(--red);font-size:18px;"><i class="fas fa-circle-dot"></i></div>
                <div class="stat-value" style="color:var(--red);"><?= formatNumber($totalIssues) ?></div>
                <div class="stat-label">Open Issues</div>
            </div>
        </div>

        <!-- ── Main Grid: Recent Repos + Right Sidebar ───────────── -->
        <div class="dashboard-grid">

            <!-- Left: Recently Updated -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-clock" style="color:var(--orange);margin-right:8px;"></i>Recently Updated</span>
                    <a href="?page=repos" class="btn btn-ghost btn-sm">View All →</a>
                </div>
                <?php $recentRepos = array_slice($repos, 0, 10); ?>
                <?php if (empty($recentRepos)): ?>
                <div class="empty-state">
                    <div class="es-icon"><i class="fas fa-folder-open"></i></div>
                    <h3>No repositories</h3>
                    <p>No repositories accessible.</p>
                </div>
                <?php else: ?>
                <table class="data-table">
                    <tbody>
                    <?php foreach ($recentRepos as $r):
                        $rOwner = $r['owner']['login'] ?? '';
                        $rName  = $r['name'] ?? '';
                    ?>
                    <tr>
                        <td style="width:36px;padding:10px 8px 10px 16px;">
                            <?php if (!empty($r['owner']['avatar_url'])): ?>
                            <img src="<?= h($r['owner']['avatar_url']) ?>" alt="" style="width:24px;height:24px;border-radius:4px;border:1px solid var(--border-color);">
                            <?php else: ?>
                            <div class="avatar-placeholder" style="width:24px;height:24px;font-size:11px;border-radius:4px;"><?= strtoupper(substr($rName, 0, 1)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px 8px;">
                            <a href="?page=repo&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>"
                               style="color:var(--blue);font-weight:500;font-size:13px;text-decoration:none;">
                                <?= h($r['full_name']) ?>
                            </a>
                            <?php if ($r['private']): ?><span class="badge badge-private" style="margin-left:5px;font-size:10px;">Private</span><?php endif; ?>
                            <?php if (!empty($r['language'])): ?>
                            <div style="margin-top:2px;display:flex;align-items:center;gap:4px;">
                                <span class="lang-dot" style="background:<?= langColor($r['language']) ?>;width:8px;height:8px;"></span>
                                <span style="font-size:11px;color:var(--text-muted);"><?= h($r['language']) ?></span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;padding:10px 8px;">
                            <?php if (($r['stars_count']??0)>0): ?>
                            <span style="font-size:12px;color:var(--yellow);"><i class="fas fa-star"></i> <?= $r['stars_count'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;font-size:12px;color:var(--text-muted);padding:10px 16px 10px 8px;">
                            <?= timeAgo($r['updated_at'] ?? $r['created_at'] ?? null) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Right sidebar -->
            <div class="right-col">

                <!-- Top Languages -->
                <?php if (!empty($topLangs)): ?>
                <div class="card">
                    <div class="card-header">
                        <span><i class="fas fa-code" style="color:var(--purple);margin-right:8px;"></i>Top Languages</span>
                    </div>
                    <div class="card-body">
                        <?php
                        $totalLangCount = array_sum($topLangs);
                        ?>
                        <!-- Stacked language bar -->
                        <div class="lang-bar" style="margin-bottom:14px;">
                            <?php foreach ($topLangs as $lang => $count):
                                $pct = round($count / $totalLangCount * 100, 1);
                            ?>
                            <div class="lang-segment" style="width:<?= $pct ?>%;background:<?= langColor($lang) ?>;"
                                 data-tip="<?= h($lang) ?>: <?= $pct ?>%"></div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($topLangs as $lang => $count):
                            $pct = round($count / $totalLangCount * 100, 1);
                        ?>
                        <div class="lang-item">
                            <div class="lang-item-left">
                                <span class="lang-dot" style="background:<?= langColor($lang) ?>;"></span>
                                <span><?= h($lang) ?></span>
                            </div>
                            <span class="lang-item-right"><?= $count ?> repo<?= $count > 1 ? 's' : '' ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <span><i class="fas fa-bolt" style="color:var(--yellow);margin-right:8px;"></i>Quick Actions</span>
                    </div>
                    <div class="quick-actions">
                        <a href="<?= h(giteaLink('repo/create')) ?>" target="_blank" class="btn btn-primary" style="justify-content:center;">
                            <i class="fas fa-plus"></i> New Repository
                        </a>
                        <a href="<?= h(giteaLink('org/create')) ?>" target="_blank" class="btn btn-secondary" style="justify-content:center;">
                            <i class="fas fa-building"></i> New Organization
                        </a>
                        <a href="?page=repos" class="btn btn-secondary" style="justify-content:center;">
                            <i class="fas fa-code-branch"></i> All Repositories
                        </a>
                        <a href="?page=users" class="btn btn-secondary" style="justify-content:center;">
                            <i class="fas fa-users"></i> Users
                        </a>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="clear_cache">
                            <button type="submit" class="btn btn-ghost" style="width:100%;justify-content:center;">
                                <i class="fas fa-broom"></i> Clear Cache
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Server Info -->
                <?php if ($siteVersion): ?>
                <div class="card">
                    <div class="card-header">
                        <span><i class="fas fa-server" style="color:var(--blue);margin-right:8px;"></i>Server Info</span>
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);display:inline-block;"></span>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="key">Gitea Version</span>
                            <span class="version-badge"><?= h($siteVersion['version'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="key">Status</span>
                            <span style="color:var(--green);font-size:13px;"><i class="fas fa-circle" style="font-size:7px;margin-right:4px;"></i>Online</span>
                        </div>
                        <div class="info-row">
                            <span class="key">Auth Mode</span>
                            <span class="value" style="color:<?= $giteaToken ? 'var(--green)' : 'var(--orange)' ?>;">
                                <?= $giteaToken ? '🔐 Token' : '👁 Public' ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="key">Repositories</span>
                            <strong style="color:var(--blue);"><?= formatNumber($totalRepos) ?></strong>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /.right-col -->
        </div><!-- /.dashboard-grid -->

        <!-- ====================================================
             REPOS PAGE  — show ALL repos, client-side filter/search
             ==================================================== -->
        <?php elseif ($page === 'repos'): ?>
        <?php
        $repos      = $pageData['repos'] ?? [];
        $totalRepos = $pageData['total_repos'] ?? count($repos);

        // Build unique owners list + per-owner counts for the filter dropdown
        $ownerCounts = [];
        foreach ($repos as $r) {
            $ol = $r['owner']['login'] ?? '';
            if ($ol) $ownerCounts[$ol] = ($ownerCounts[$ol] ?? 0) + 1;
        }
        arsort($ownerCounts);
        ?>

        <!-- ── Page Title ────────────────────────────────────────── -->
        <div class="page-title-row">
            <div class="page-title">
                <h1><i class="fas fa-code-branch" style="color:var(--green);margin-right:10px;"></i>Repositories
                    <span id="repoCounter" style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:8px;"><?= $totalRepos ?> total</span>
                </h1>
                <p>All repositories at <?= h(APP_DOMAIN) ?></p>
            </div>
            <a href="<?= h(giteaLink('repo/create')) ?>" target="_blank" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Repository
            </a>
        </div>

        <!-- ── Toolbar ───────────────────────────────────────────── -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
            <!-- Live search -->
            <div style="position:relative;flex:1;min-width:200px;">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;"></i>
                <input id="repoSearch" type="text" placeholder="Search name or description…"
                       class="form-input" style="padding-left:32px;"
                       value="<?= h($currentSearch) ?>">
            </div>
            <!-- Owner filter -->
            <select id="ownerFilter" class="form-input" style="width:auto;min-width:130px;">
                <option value="">All owners</option>
                <?php foreach ($ownerCounts as $ol => $cnt): ?>
                <option value="<?= h(strtolower($ol)) ?>"><?= h($ol) ?> (<?= $cnt ?>)</option>
                <?php endforeach; ?>
            </select>
            <!-- Sort (server-side) -->
            <form method="GET" style="margin:0;">
                <input type="hidden" name="page" value="repos">
                <select name="sort" onchange="this.form.submit()" class="form-input" style="width:auto;min-width:110px;">
                    <option value="updated" <?= $currentSort==='updated'?'selected':'' ?>>Updated</option>
                    <option value="created" <?= $currentSort==='created'?'selected':'' ?>>Created</option>
                    <option value="stars"   <?= $currentSort==='stars'  ?'selected':'' ?>>Stars</option>
                    <option value="forks"   <?= $currentSort==='forks'  ?'selected':'' ?>>Forks</option>
                    <option value="alpha"   <?= $currentSort==='alpha'  ?'selected':'' ?>>A–Z</option>
                </select>
            </form>
            <!-- View toggle -->
            <div style="display:flex;gap:2px;background:var(--bg-tertiary);border:1px solid var(--border-color);border-radius:var(--radius);padding:2px;">
                <button id="btnGrid" onclick="setView('grid')" class="btn btn-secondary btn-icon" title="Grid view"><i class="fas fa-th-large"></i></button>
                <button id="btnList" onclick="setView('list')" class="btn btn-ghost btn-icon"     title="List view"><i class="fas fa-list"></i></button>
            </div>
        </div>

        <?php if (empty($repos)): ?>
        <div class="empty-state card">
            <div class="icon"><i class="fas fa-code-branch"></i></div>
            <h3>No repositories found</h3>
            <p>No repositories available.</p>
        </div>
        <?php else: ?>

        <!-- No-match message (hidden by default) -->
        <div id="noMatch" style="display:none;" class="empty-state card">
            <div class="icon"><i class="fas fa-search"></i></div>
            <h3>No results</h3>
            <p>No repositories match your filter. <a href="#" onclick="clearFilters();return false;" style="color:var(--blue);">Clear filters</a></p>
        </div>

        <!-- ── GRID VIEW ── -->
        <div id="viewGrid" class="grid-repos">
        <?php foreach ($repos as $r):
            $rOwner = $r['owner']['login'] ?? '';
            $rName  = $r['name'] ?? '';
            $rFull  = strtolower($r['full_name'] ?? '');
            $rDesc  = strtolower($r['description'] ?? '');
        ?>
        <div class="repo-card" data-owner="<?= h(strtolower($rOwner)) ?>" data-search="<?= h($rFull . ' ' . $rDesc) ?>">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                    <?php if (!empty($r['owner']['avatar_url'])): ?>
                    <img src="<?= h($r['owner']['avatar_url']) ?>" alt="" style="width:22px;height:22px;border-radius:4px;flex-shrink:0;border:1px solid var(--border-color);">
                    <?php endif; ?>
                    <a href="?page=repo&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>" class="repo-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($r['full_name']) ?>
                    </a>
                </div>
                <div style="display:flex;gap:4px;flex-shrink:0;align-items:center;">
                    <?= visibility_badge($r['private'] ?? false, $r['fork'] ?? false) ?>
                    <?php if ($r['archived'] ?? false): ?><span class="badge badge-archived">📦</span><?php endif; ?>
                </div>
            </div>
            <?php if (!empty($r['description'])): ?>
            <p class="repo-desc"><?= h($r['description']) ?></p>
            <?php else: ?>
            <p class="repo-desc" style="font-style:italic;opacity:.35;">No description</p>
            <?php endif; ?>
            <?php if (!empty($r['topics'])): ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;">
                <?php foreach (array_slice($r['topics'],0,3) as $topic): ?>
                <span class="badge-topic"><?= h($topic) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="repo-meta">
                <?php if (!empty($r['language'])): ?>
                <span><span class="lang-dot" style="background:<?= langColor($r['language']) ?>;"></span><?= h($r['language']) ?></span>
                <?php endif; ?>
                <?php if (($r['stars_count']??0)>0): ?><span style="color:#fbbf24;"><i class="fas fa-star"></i> <?= $r['stars_count'] ?></span><?php endif; ?>
                <?php if (($r['forks_count']??0)>0): ?><span><i class="fas fa-code-fork"></i> <?= $r['forks_count'] ?></span><?php endif; ?>
                <span style="margin-left:auto;"><i class="fas fa-clock"></i> <?= timeAgo($r['updated_at']??$r['created_at']??null) ?></span>
            </div>
            <div style="display:flex;gap:6px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border-color);">
                <a href="?page=repo&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>" class="btn btn-secondary" style="font-size:12px;flex:1;justify-content:center;"><i class="fas fa-info-circle"></i> Details</a>
                <a href="?page=download&type=archive&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>&ref=<?= urlencode($r['default_branch'] ?? 'main') ?>&format=zip" class="btn btn-ghost" style="font-size:12px;padding:5px 9px;" title="Download ZIP"><i class="fas fa-download"></i></a>
                <a href="<?= h(giteaLink($r['full_name'])) ?>" target="_blank" class="btn btn-blue" style="font-size:12px;flex:1;justify-content:center;"><i class="fas fa-external-link-alt"></i> Open</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- ── LIST VIEW (initially hidden) ── -->
        <div id="viewList" style="display:none;">
        <div class="card" style="padding:0;overflow:hidden;">
        <table class="data-table" style="width:100%;">
        <thead>
            <tr style="background:var(--bg-tertiary);">
                <th style="padding:10px 14px;font-size:12px;text-align:left;color:var(--text-muted);font-weight:600;">Repository</th>
                <th style="padding:10px 14px;font-size:12px;text-align:left;color:var(--text-muted);font-weight:600;">Language</th>
                <th style="padding:10px 14px;font-size:12px;text-align:center;color:var(--text-muted);font-weight:600;">Stars</th>
                <th style="padding:10px 14px;font-size:12px;text-align:right;color:var(--text-muted);font-weight:600;">Updated</th>
                <th style="padding:10px 14px;font-size:12px;text-align:right;color:var(--text-muted);font-weight:600;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($repos as $r):
            $rOwner = $r['owner']['login'] ?? '';
            $rName  = $r['name'] ?? '';
            $rFull  = strtolower($r['full_name'] ?? '');
            $rDesc  = strtolower($r['description'] ?? '');
        ?>
        <tr class="repo-row" data-owner="<?= h(strtolower($rOwner)) ?>" data-search="<?= h($rFull . ' ' . $rDesc) ?>"
            style="border-bottom:1px solid var(--border-color);">
            <td style="padding:10px 14px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if (!empty($r['owner']['avatar_url'])): ?>
                    <img src="<?= h($r['owner']['avatar_url']) ?>" alt="" style="width:20px;height:20px;border-radius:3px;flex-shrink:0;border:1px solid var(--border-color);">
                    <?php endif; ?>
                    <div style="min-width:0;">
                        <a href="?page=repo&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>"
                           style="color:var(--blue);font-weight:500;font-size:13px;text-decoration:none;white-space:nowrap;">
                            <?= h($r['full_name']) ?>
                        </a>
                        <?= visibility_badge($r['private']??false, $r['fork']??false) ?>
                        <?php if ($r['archived']??false): ?><span class="badge badge-archived" style="font-size:10px;">📦</span><?php endif; ?>
                        <?php if (!empty($r['description'])): ?>
                        <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px;"><?= h($r['description']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td style="padding:10px 14px;white-space:nowrap;">
                <?php if (!empty($r['language'])): ?>
                <span style="font-size:12px;"><span class="lang-dot" style="background:<?= langColor($r['language']) ?>;"></span><?= h($r['language']) ?></span>
                <?php else: ?><span style="color:var(--text-muted);font-size:12px;">—</span><?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:center;white-space:nowrap;">
                <?php if (($r['stars_count']??0)>0): ?>
                <span style="font-size:12px;color:#fbbf24;"><i class="fas fa-star"></i> <?= $r['stars_count'] ?></span>
                <?php else: ?><span style="color:var(--text-muted);font-size:12px;">—</span><?php endif; ?>
            </td>
            <td style="padding:10px 14px;text-align:right;white-space:nowrap;font-size:12px;color:var(--text-muted);">
                <?= timeAgo($r['updated_at']??$r['created_at']??null) ?>
            </td>
            <td style="padding:10px 14px;text-align:right;white-space:nowrap;">
                <a href="?page=repo&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>" class="btn btn-ghost" style="font-size:11px;padding:3px 8px;">Details</a>
                <a href="?page=download&type=archive&owner=<?= urlencode($rOwner) ?>&repo=<?= urlencode($rName) ?>&ref=<?= urlencode($r['default_branch'] ?? 'main') ?>&format=zip" class="btn btn-ghost" style="font-size:11px;padding:3px 8px;" title="Download ZIP"><i class="fas fa-download"></i></a>
                <a href="<?= h(giteaLink($r['full_name'])) ?>" target="_blank" class="btn btn-blue" style="font-size:11px;padding:3px 8px;">Open</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        </div>

        <!-- Result counter -->
        <div id="filterInfo" style="margin-top:14px;font-size:13px;color:var(--text-muted);text-align:right;display:none;">
            Showing <strong id="visibleCount">0</strong> of <?= $totalRepos ?> repositories
        </div>

        <?php endif; // repos not empty ?>

        <script>
        // ── Repos page client-side filter ──────────────────────────────
        (function(){
            var currentView = localStorage.getItem('reposView') || 'grid';
            setView(currentView);

            var searchEl  = document.getElementById('repoSearch');
            var ownerEl   = document.getElementById('ownerFilter');
            var filterInfo= document.getElementById('filterInfo');
            var visCount  = document.getElementById('visibleCount');
            var counter   = document.getElementById('repoCounter');
            var noMatch   = document.getElementById('noMatch');

            function applyFilters() {
                var q     = (searchEl.value || '').toLowerCase().trim();
                var owner = (ownerEl.value || '').toLowerCase();
                var cards = document.querySelectorAll('.repo-card, .repo-row');
                var shown = 0;
                cards.forEach(function(el){
                    var s   = (el.dataset.search || '').toLowerCase();
                    var o   = (el.dataset.owner  || '').toLowerCase();
                    var ok  = (!q || s.includes(q)) && (!owner || o === owner);
                    el.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });
                var total = <?= $totalRepos ?>;
                if (q || owner) {
                    filterInfo.style.display = 'block';
                    visCount.textContent = shown;
                    counter.textContent  = shown + ' / ' + total + ' total';
                } else {
                    filterInfo.style.display = 'none';
                    counter.textContent  = total + ' total';
                }
                noMatch.style.display = (shown === 0 && (q || owner)) ? 'block' : 'none';
            }

            var timer;
            searchEl.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(applyFilters, 180); });
            ownerEl.addEventListener('change', applyFilters);

            // Restore search from URL if any
            var urlQ = '<?= addslashes($currentSearch) ?>';
            if (urlQ) { searchEl.value = urlQ; applyFilters(); }
        })();

        function setView(v) {
            var g = document.getElementById('viewGrid');
            var l = document.getElementById('viewList');
            var bg= document.getElementById('btnGrid');
            var bl= document.getElementById('btnList');
            if (!g || !l) return;
            if (v === 'list') {
                g.style.display='none'; l.style.display='';
                if(bg) bg.className='btn btn-ghost'; if(bl) bl.className='btn btn-secondary';
            } else {
                g.style.display=''; l.style.display='none';
                if(bg) bg.className='btn btn-secondary'; if(bl) bl.className='btn btn-ghost';
            }
            localStorage.setItem('reposView', v);
        }

        function clearFilters() {
            document.getElementById('repoSearch').value = '';
            document.getElementById('ownerFilter').value = '';
            document.querySelectorAll('.repo-card,.repo-row').forEach(function(el){ el.style.display=''; });
            document.getElementById('noMatch').style.display='none';
            document.getElementById('filterInfo').style.display='none';
            document.getElementById('repoCounter').textContent = '<?= $totalRepos ?> total';
        }
        </script>

        <!-- ====================================================
             REPO DETAIL PAGE
             ==================================================== -->
        <?php elseif ($page === 'repo'):
        $repo     = $pageData['repo'] ?? null;
        $branches = $pageData['branches'] ?? [];
        $releases = $pageData['releases'] ?? [];
        $issues   = $pageData['issues'] ?? [];
        $commits  = $pageData['commits'] ?? [];
        $languages = $pageData['languages'] ?? [];
        $topics   = $pageData['topics']['topics'] ?? [];
        $tags     = $pageData['tags'] ?? [];
        $owner    = $_GET['owner'] ?? '';
        $repoName = $_GET['repo'] ?? '';

        if (!$repo):
        ?>
        <div style="max-width:520px;margin:40px auto;">
            <div class="card" style="border-color:rgba(248,81,73,.3);text-align:center;padding:32px 28px;">
                <div style="font-size:52px;margin-bottom:16px;">🔒</div>
                <h2 style="font-size:18px;font-weight:700;margin:0 0 8px;color:var(--red);">Repository Tidak Ditemukan</h2>
                <p style="font-size:13px;color:var(--text-secondary);margin:0 0 20px;line-height:1.6;">
                    <code style="background:var(--bg-tertiary);padding:2px 8px;border-radius:4px;font-size:12px;">
                        <?= h($owner) ?>/<?= h($repoName) ?>
                    </code><br>
                    Repository tidak dapat diakses. Mungkin private, dihapus, atau Anda tidak punya izin.
                </p>
                <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                    <a href="?page=repos" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Kembali</a>
                    <a href="?page=repo&owner=<?= urlencode($owner) ?>&repo=<?= urlencode($repoName) ?>&nocache=1" class="btn btn-secondary"><i class="fas fa-rotate-right"></i> Retry</a>
                    <a href="<?= h(giteaLink($owner.'/'.$repoName)) ?>" target="_blank" class="btn btn-blue"><i class="fas fa-external-link-alt"></i> Gitea</a>
                </div>
            </div>
        </div>
        <?php else: ?>

        <!-- Flash messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">
            <i class="fas fa-circle-check"></i> <?= h($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); endif;
        if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:14px;">
            <i class="fas fa-triangle-exclamation"></i> <?= h($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); endif; ?>

        <!-- ── Repo Header ─────────────────────────────────────── -->
        <div class="repo-header" style="margin-bottom:20px;">
            <!-- Breadcrumb -->
            <div class="breadcrumb" style="margin-bottom:12px;">
                <a href="?page=repos"><i class="fas fa-code-branch" style="margin-right:4px;"></i>Repositories</a>
                <span class="sep">/</span>
                <a href="?page=user&username=<?= urlencode($repo['owner']['login'] ?? '') ?>"><?= h($repo['owner']['login'] ?? '') ?></a>
                <span class="sep">/</span>
                <strong style="color:var(--text-primary);"><?= h($repo['name'] ?? '') ?></strong>
            </div>

            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <!-- Owner avatar + repo name -->
                <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0;">
                    <?php if (!empty($repo['owner']['avatar_url'])): ?>
                    <img src="<?= h($repo['owner']['avatar_url']) ?>" alt=""
                         style="width:48px;height:48px;border-radius:var(--radius-lg);border:2px solid var(--border-color);flex-shrink:0;">
                    <?php endif; ?>
                    <div style="min-width:0;">
                        <h1 style="font-size:22px;font-weight:700;margin:0 0 6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <a href="?page=user&username=<?= urlencode($repo['owner']['login'] ?? '') ?>"
                               style="color:var(--text-secondary);font-weight:400;font-size:18px;text-decoration:none;">
                                <?= h($repo['owner']['login'] ?? '') ?>
                            </a>
                            <span style="color:var(--text-muted);font-weight:300;">/</span>
                            <span style="color:var(--text-primary);"><?= h($repo['name'] ?? '') ?></span>
                        </h1>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <?= visibility_badge($repo['private'] ?? false, $repo['fork'] ?? false) ?>
                            <?php if ($repo['archived'] ?? false): ?><span class="badge badge-archived">📦 Archived</span><?php endif; ?>
                            <?php foreach ($topics as $topic): ?>
                            <span class="badge-topic"><?= h($topic) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <!-- Action buttons -->
                <div style="display:flex;gap:8px;flex-wrap:wrap;flex-shrink:0;">
                    <a href="<?= h(giteaLink($repo['full_name'])) ?>" target="_blank" class="btn btn-primary">
                        <i class="fas fa-external-link-alt"></i> Open in Gitea
                    </a>
                    <?php if (!($repo['archived'] ?? false)): ?>
                    <a href="<?= h(giteaLink($repo['full_name'] . '/issues/new')) ?>" target="_blank" class="btn btn-secondary">
                        <i class="fas fa-plus"></i> New Issue
                    </a>
                    <?php endif; ?>
                    <!-- Download dropdown -->
                    <div class="dl-dropdown" style="position:relative;">
                        <button class="btn btn-secondary" onclick="toggleDlMenu(this)" style="display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-download"></i> Download <i class="fas fa-chevron-down" style="font-size:10px;"></i>
                        </button>
                        <div class="dl-menu" style="display:none;position:absolute;right:0;top:calc(100% + 4px);background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius);min-width:200px;z-index:50;box-shadow:0 8px 24px rgba(0,0,0,.4);overflow:hidden;">
                            <?php
                            $dlRef = $repo['default_branch'] ?? 'main';
                            $dlOwnerR = $repo['owner']['login'] ?? '';
                            $dlNameR  = $repo['name'] ?? '';
                            ?>
                            <div style="padding:6px 10px;font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border-color);font-weight:600;">Archive — <?= h($dlRef) ?></div>
                            <a href="?page=download&type=archive&owner=<?= urlencode($dlOwnerR) ?>&repo=<?= urlencode($dlNameR) ?>&ref=<?= urlencode($dlRef) ?>&format=zip"
                               class="dl-menu-item" style="display:flex;align-items:center;gap:8px;padding:9px 12px;color:var(--text-primary);text-decoration:none;font-size:13px;transition:background .15s;">
                                <i class="fas fa-file-zipper" style="color:var(--blue);width:16px;text-align:center;"></i>
                                Download ZIP
                                <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">.zip</span>
                            </a>
                            <a href="?page=download&type=archive&owner=<?= urlencode($dlOwnerR) ?>&repo=<?= urlencode($dlNameR) ?>&ref=<?= urlencode($dlRef) ?>&format=tar.gz"
                               class="dl-menu-item" style="display:flex;align-items:center;gap:8px;padding:9px 12px;color:var(--text-primary);text-decoration:none;font-size:13px;transition:background .15s;">
                                <i class="fas fa-file-code" style="color:var(--green);width:16px;text-align:center;"></i>
                                Download TAR.GZ
                                <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">.tar.gz</span>
                            </a>
                            <?php if (!empty($branches) && count($branches) > 1): ?>
                            <div style="padding:6px 10px;font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);font-weight:600;">Other Branches</div>
                            <?php foreach (array_slice($branches, 0, 5) as $dlBranch): if (($dlBranch['name']??'') === $dlRef) continue; ?>
                            <a href="?page=download&type=archive&owner=<?= urlencode($dlOwnerR) ?>&repo=<?= urlencode($dlNameR) ?>&ref=<?= urlencode($dlBranch['name']) ?>&format=zip"
                               class="dl-menu-item" style="display:flex;align-items:center;gap:8px;padding:8px 12px;color:var(--text-secondary);text-decoration:none;font-size:12px;transition:background .15s;">
                                <i class="fas fa-code-branch" style="color:var(--text-muted);width:16px;text-align:center;"></i>
                                <?= h($dlBranch['name']) ?> .zip
                            </a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($repo['description'])): ?>
            <p style="color:var(--text-secondary);font-size:14px;margin:14px 0 0;line-height:1.6;"><?= h($repo['description']) ?></p>
            <?php endif; ?>
            <?php if (!empty($repo['website'])): ?>
            <a href="<?= h($repo['website']) ?>" target="_blank" style="color:var(--blue);font-size:13px;display:inline-flex;align-items:center;gap:6px;margin-top:8px;">
                <i class="fas fa-link"></i> <?= h($repo['website']) ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- ── Stats Row ───────────────────────────────────────── -->
        <div class="stats-row">
            <div class="stat-pill">
                <i class="fas fa-star" style="color:var(--yellow);"></i>
                <strong><?= formatNumber($repo['stars_count'] ?? 0) ?></strong>
                <span>Stars</span>
            </div>
            <div class="stat-pill">
                <i class="fas fa-code-fork" style="color:var(--purple);"></i>
                <strong><?= formatNumber($repo['forks_count'] ?? 0) ?></strong>
                <span>Forks</span>
            </div>
            <div class="stat-pill">
                <i class="fas fa-eye" style="color:var(--blue);"></i>
                <strong><?= formatNumber($repo['watchers_count'] ?? 0) ?></strong>
                <span>Watchers</span>
            </div>
            <div class="stat-pill">
                <i class="fas fa-circle-dot" style="color:var(--red);"></i>
                <strong><?= $repo['open_issues_count'] ?? 0 ?></strong>
                <span>Issues</span>
            </div>
            <div class="stat-pill">
                <i class="fas fa-code-branch" style="color:var(--green);"></i>
                <strong><?= count($branches) ?></strong>
                <span>Branches</span>
            </div>
            <?php if ($repo['size'] ?? 0): ?>
            <div class="stat-pill">
                <i class="fas fa-hdd" style="color:var(--orange);"></i>
                <strong><?= round(($repo['size'] ?? 0) / 1024, 1) ?> MB</strong>
                <span>Size</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Two-column layout ───────────────────────────────── -->
        <div class="repo-detail-grid">

        <!-- ── Left column ──────────────────────────────────────── -->
        <div>

            <!-- ── File Browser ───────────────────────────────────── -->
            <?php
            $fileTree   = $pageData['file_tree']  ?? [];
            $browseRef  = $pageData['browse_ref']  ?? ($repo['default_branch'] ?? 'main');
            $browsePath = $pageData['browse_path'] ?? '';
            $browseOwner= $repo['owner']['login']  ?? '';
            $browseName = $repo['name'] ?? '';
            $pathParts  = $browsePath !== '' ? explode('/', $browsePath) : [];
            ?>
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span><i class="fas fa-folder-open" style="color:var(--yellow);margin-right:6px;"></i>Files</span>
                        <!-- Branch selector -->
                        <form method="GET" style="margin:0;display:flex;align-items:center;gap:4px;">
                            <input type="hidden" name="page"  value="repo">
                            <input type="hidden" name="owner" value="<?= h($browseOwner) ?>">
                            <input type="hidden" name="repo"  value="<?= h($browseName) ?>">
                            <input type="hidden" name="path"  value="<?= h($browsePath) ?>">
                            <i class="fas fa-code-branch" style="color:var(--green);font-size:12px;"></i>
                            <select name="ref" onchange="this.form.submit()" class="form-input" style="font-size:12px;padding:3px 8px;width:auto;min-width:100px;">
                                <?php foreach ($branches as $br): ?>
                                <option value="<?= h($br['name']) ?>" <?= ($br['name'] === $browseRef) ? 'selected' : '' ?>><?= h($br['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <!-- Path breadcrumb -->
                        <div style="font-size:13px;display:flex;align-items:center;gap:3px;flex-wrap:wrap;">
                            <a href="?page=repo&owner=<?= urlencode($browseOwner) ?>&repo=<?= urlencode($browseName) ?>&ref=<?= urlencode($browseRef) ?>"
                               style="color:var(--blue);text-decoration:none;font-weight:600;"><?= h($browseName) ?></a>
                            <?php
                            $cumPath = '';
                            foreach ($pathParts as $pi => $part):
                                $cumPath = $cumPath !== '' ? $cumPath.'/'.$part : $part;
                            ?>
                            <span style="color:var(--text-muted);">/</span>
                            <?php if ($pi < count($pathParts)-1): ?>
                            <a href="?page=repo&owner=<?= urlencode($browseOwner) ?>&repo=<?= urlencode($browseName) ?>&ref=<?= urlencode($browseRef) ?>&path=<?= urlencode($cumPath) ?>"
                               style="color:var(--blue);text-decoration:none;"><?= h($part) ?></a>
                            <?php else: ?>
                            <span style="color:var(--text-primary);font-weight:500;"><?= h($part) ?></span>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="?page=file&action=new&owner=<?= urlencode($browseOwner) ?>&repo=<?= urlencode($browseName) ?>&path=<?= urlencode($browsePath) ?>&ref=<?= urlencode($browseRef) ?>"
                       class="btn btn-primary btn-sm" style="font-size:12px;">
                        <i class="fas fa-plus"></i> New File
                    </a>
                </div>

                <?php if (empty($fileTree)): ?>
                <div style="padding:28px 20px;text-align:center;color:var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;opacity:.4;"></i>
                    <?= ($repo['empty'] ?? false) ? 'Repository kosong — buat file pertama.' : 'Direktori kosong atau gagal dimuat.' ?>
                </div>
                <?php else: ?>
                <table style="width:100%;border-collapse:collapse;">
                    <?php if ($browsePath !== ''): ?>
                    <tr style="border-bottom:1px solid var(--border-color);">
                        <td style="padding:8px 14px;width:28px;"><i class="fas fa-folder" style="color:var(--yellow);font-size:13px;"></i></td>
                        <td style="padding:8px 4px;" colspan="3">
                            <?php
                            $parentPath = dirname($browsePath);
                            $parentPath = ($parentPath === '.') ? '' : $parentPath;
                            ?>
                            <a href="?page=repo&owner=<?= urlencode($browseOwner) ?>&repo=<?= urlencode($browseName) ?>&ref=<?= urlencode($browseRef) ?>&path=<?= urlencode($parentPath) ?>"
                               style="color:var(--text-muted);text-decoration:none;font-size:13px;">..</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($fileTree as $item):
                        $iType  = $item['type'] ?? 'file';
                        $iName  = $item['name'] ?? '';
                        $iPath  = $item['path'] ?? ($browsePath !== '' ? $browsePath.'/'.$iName : $iName);
                        $iSize  = $item['size'] ?? 0;
                        $isDir  = $iType === 'dir';
                        $isLink = $iType === 'symlink';
                        if ($isDir) {
                            $href = '?page=repo&owner='.urlencode($browseOwner).'&repo='.urlencode($browseName)
                                   .'&ref='.urlencode($browseRef).'&path='.urlencode($iPath);
                        } else {
                            $href = '?page=file&owner='.urlencode($browseOwner).'&repo='.urlencode($browseName)
                                   .'&path='.urlencode($iPath).'&ref='.urlencode($browseRef);
                        }
                        $ext = strtolower(pathinfo($iName, PATHINFO_EXTENSION));
                        $iconClass = 'fas fa-file'; $iconColor = 'var(--text-muted)';
                        if ($isDir)  { $iconClass='fas fa-folder';       $iconColor='var(--yellow)'; }
                        elseif ($isLink) { $iconClass='fas fa-link';     $iconColor='var(--blue)'; }
                        elseif (in_array($ext,['php','js','ts','tsx','jsx','py','rb','go','java','c','cpp','cs','swift','kt','rs','lua'])) { $iconClass='fas fa-file-code'; $iconColor='var(--blue)'; }
                        elseif (in_array($ext,['md','txt','rst','log'])) { $iconClass='fas fa-file-lines';   $iconColor='var(--text-secondary)'; }
                        elseif (in_array($ext,['png','jpg','jpeg','gif','svg','webp','ico','bmp'])) { $iconClass='fas fa-file-image'; $iconColor='var(--purple)'; }
                        elseif (in_array($ext,['zip','tar','gz','bz2','rar','7z','xz'])) { $iconClass='fas fa-file-zipper'; $iconColor='var(--orange)'; }
                        elseif (in_array($ext,['pdf'])) { $iconClass='fas fa-file-pdf';    $iconColor='#f55'; }
                        elseif (in_array($ext,['json','xml','yaml','yml','toml','ini','env','cfg','conf'])) { $iconClass='fas fa-file-code'; $iconColor='var(--green)'; }
                        elseif (in_array($ext,['sh','bash','zsh','fish','bat','cmd','ps1'])) { $iconClass='fas fa-terminal'; $iconColor='var(--green)'; }
                        elseif (in_array($ext,['html','htm','css','scss','sass','less'])) { $iconClass='fas fa-file-code'; $iconColor='var(--orange)'; }
                        elseif ($iName === 'LICENSE' || $iName === 'Makefile' || $iName === 'Dockerfile') { $iconClass='fas fa-file-shield'; $iconColor='var(--text-secondary)'; }
                        elseif (str_starts_with($iName,'.')) { $iconClass='fas fa-file'; $iconColor='var(--text-muted)'; }
                    ?>
                    <tr class="file-row" style="border-bottom:1px solid var(--border-color);transition:background .12s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
                        <td style="padding:7px 14px;width:28px;">
                            <i class="<?= $iconClass ?>" style="color:<?= $iconColor ?>;font-size:13px;"></i>
                        </td>
                        <td style="padding:7px 4px;">
                            <a href="<?= h($href) ?>" style="color:<?= $isDir ? 'var(--text-primary)' : 'var(--blue)' ?>;text-decoration:none;font-size:13px;font-weight:<?= $isDir ? '500':'400' ?>;">
                                <?= h($iName) ?></a>
                            <?php if ($isLink): ?><span style="font-size:10px;color:var(--text-muted);margin-left:4px;">symlink</span><?php endif; ?>
                        </td>
                        <td style="padding:7px 14px;max-width:260px;overflow:hidden;">
                            <?php
                            $commitMsg  = $item['_commit_message'] ?? '';
                            $commitSha  = $item['_commit_sha']     ?? '';
                            $commitUrl  = $item['_commit_url']     ?? '#';
                            $commitDate = $item['last_committer_date'] ?? $item['last_author_date'] ?? null;
                            if ($commitMsg !== ''):
                            ?>
                            <a href="<?= h($commitUrl) ?>" target="_blank"
                               style="color:var(--text-secondary);font-size:12px;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:240px;"
                               title="<?= h($commitMsg) ?>">
                                <?= h(mb_strimwidth($commitMsg, 0, 52, '…')) ?>
                            </a>
                            <div style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                                <?php if ($commitSha !== ''): ?>
                                <code style="font-size:10px;color:var(--purple);font-family:'JetBrains Mono',monospace;opacity:.9;"><?= h($commitSha) ?></code>
                                <?php endif; ?>
                                <?php if ($commitDate): ?>
                                <span style="font-size:11px;color:var(--text-muted);" title="<?= h($commitDate) ?>">
                                    <?= timeAgo($commitDate) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($commitDate): ?>
                            <span style="font-size:12px;color:var(--text-muted);" title="<?= h($commitDate) ?>">
                                <?= timeAgo($commitDate) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:7px 14px;text-align:right;font-size:11px;color:var(--text-muted);white-space:nowrap;min-width:60px;">
                            <?= (!$isDir && $iSize > 0) ? ($iSize>=1024 ? round($iSize/1024,1).' KB' : $iSize.' B') : '' ?>
                        </td>
                        <td style="padding:6px 10px 6px 0;text-align:right;white-space:nowrap;min-width:96px;">
                            <?php if (!$isDir): ?>
                            <a href="<?= h($href) ?>"
                               class="btn btn-ghost" style="font-size:11px;padding:2px 7px;" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=file&action=edit&owner=<?= urlencode($browseOwner) ?>&repo=<?= urlencode($browseName) ?>&path=<?= urlencode($iPath) ?>&ref=<?= urlencode($browseRef) ?>"
                               class="btn btn-ghost" style="font-size:11px;padding:2px 7px;" title="Edit"><i class="fas fa-pen"></i></a>
                            <a href="?page=download&type=raw&owner=<?= urlencode($browseOwner) ?>&repo=<?= urlencode($browseName) ?>&path=<?= urlencode($iPath) ?>&ref=<?= urlencode($browseRef) ?>"
                               class="btn btn-ghost" style="font-size:11px;padding:2px 7px;" title="Download"><i class="fas fa-download"></i></a>
                            <?php else: ?>
                            <a href="<?= h($href) ?>"
                               class="btn btn-ghost" style="font-size:11px;padding:2px 7px;" title="Open"><i class="fas fa-folder-open"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>
            </div>
            <!-- /File Browser ────────────────────────────────────── -->

            <!-- Clone URLs -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">
                    <span><i class="fas fa-clone" style="color:var(--green);margin-right:8px;"></i>Clone Repository</span>
                </div>
                <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">HTTPS</div>
                        <div class="clone-box">
                            <i class="fas fa-lock" style="color:var(--blue);flex-shrink:0;"></i>
                            <code id="clone-https"><?= h($repo['clone_url'] ?? '') ?></code>
                            <button class="copy-btn" onclick="copyText('clone-https', this)"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">SSH</div>
                        <div class="clone-box">
                            <i class="fas fa-key" style="color:var(--yellow);flex-shrink:0;"></i>
                            <code id="clone-ssh"><?= h($repo['ssh_url'] ?? '') ?></code>
                            <button class="copy-btn" onclick="copyText('clone-ssh', this)"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Git Command</div>
                        <div class="clone-box">
                            <i class="fas fa-terminal" style="color:var(--purple);flex-shrink:0;"></i>
                            <code id="clone-cmd">git clone <?= h($repo['clone_url'] ?? '') ?></code>
                            <button class="copy-btn" onclick="copyText('clone-cmd', this)"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Languages -->
            <?php if (!empty($languages)): ?>
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">
                    <span><i class="fas fa-code" style="color:var(--purple);margin-right:8px;"></i>Languages</span>
                </div>
                <div class="card-body">
                    <?php
                    $totalBytes = array_sum($languages);
                    ?>
                    <div class="lang-bar" style="margin-bottom:14px;height:10px;">
                        <?php foreach ($languages as $lang => $bytes):
                            $pct = round($bytes / $totalBytes * 100, 1);
                        ?>
                        <div class="lang-segment" style="width:<?= $pct ?>%;background:<?= langColor($lang) ?>;" data-tip="<?= h($lang) ?>: <?= $pct ?>%"></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        <?php foreach ($languages as $lang => $bytes):
                            $pct = round($bytes / $totalBytes * 100, 1);
                        ?>
                        <div style="display:flex;align-items:center;gap:6px;font-size:13px;">
                            <span class="lang-dot" style="background:<?= langColor($lang) ?>;"></span>
                            <span><?= h($lang) ?></span>
                            <span style="color:var(--text-muted);"><?= $pct ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Commits -->
            <?php if (!empty($commits)): ?>
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">
                    <span><i class="fas fa-history" style="color:var(--blue);margin-right:8px;"></i>Recent Commits</span>
                    <a href="<?= h(giteaLink($repo['full_name'] . '/commits/branch/' . ($repo['default_branch'] ?? 'main'))) ?>" target="_blank" class="btn btn-ghost btn-sm">All Commits →</a>
                </div>
                <div style="padding:0 16px;">
                    <?php foreach ($commits as $commit): ?>
                    <div class="commit-item">
                        <?php if (!empty($commit['author']['avatar_url'])): ?>
                        <img src="<?= h($commit['author']['avatar_url']) ?>" alt="" style="width:28px;height:28px;border-radius:50%;flex-shrink:0;border:1px solid var(--border-color);">
                        <?php else: ?>
                        <div class="avatar-placeholder" style="width:28px;height:28px;font-size:12px;flex-shrink:0;">
                            <?= strtoupper(substr($commit['commit']['author']['name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <a href="<?= h(giteaLink($repo['full_name'] . '/commit/' . ($commit['sha'] ?? ''))) ?>" target="_blank"
                                   style="font-size:13px;color:var(--text-primary);text-decoration:none;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:400px;"
                                   title="<?= h($commit['commit']['message'] ?? '') ?>">
                                    <?= h(strtok($commit['commit']['message'] ?? 'No message', "\n")) ?>
                                </a>
                                <span class="commit-sha"><?= substr($commit['sha'] ?? '', 0, 8) ?></span>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">
                                <i class="fas fa-user" style="margin-right:4px;"></i><?= h($commit['commit']['author']['name'] ?? '') ?>
                                &nbsp;·&nbsp;
                                <i class="fas fa-clock" style="margin-right:4px;"></i><?= timeAgo($commit['commit']['author']['date'] ?? date('c')) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Open Issues -->
            <?php if (!empty($issues)): ?>
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-circle-dot" style="color:var(--red);margin-right:8px;"></i>Open Issues</span>
                    <a href="<?= h(giteaLink($repo['full_name'] . '/issues')) ?>" target="_blank" class="btn btn-ghost btn-sm">All Issues →</a>
                </div>
                <div style="padding:0;">
                    <table class="data-table">
                        <tbody>
                        <?php foreach (array_slice($issues, 0, 8) as $issue): ?>
                        <tr>
                            <td style="width:40px;color:var(--text-muted);font-size:12px;font-family:monospace;">#<?= $issue['number'] ?></td>
                            <td>
                                <a href="<?= h($issue['html_url'] ?? '') ?>" target="_blank"
                                   style="color:var(--text-primary);text-decoration:none;font-size:13px;font-weight:500;">
                                    <?= h($issue['title'] ?? '') ?>
                                </a>
                                <?php if (!empty($issue['labels'])): ?>
                                <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;">
                                    <?php foreach ($issue['labels'] as $label): ?>
                                    <span style="background:#<?= h($label['color'] ?? '888') ?>22;color:#<?= h($label['color'] ?? '888') ?>;border:1px solid #<?= h($label['color'] ?? '888') ?>55;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:500;">
                                        <?= h($label['name'] ?? '') ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;font-size:12px;color:var(--text-muted);white-space:nowrap;">
                                <?= timeAgo($issue['created_at'] ?? date('c')) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div><!-- /.left-col -->

        <!-- ── Right sidebar widgets ──────────────────────────── -->
        <div>
            <!-- About / Info widget -->
            <div class="repo-sidebar-widget">
                <div class="w-header">About</div>
                <div class="w-body">
                    <div class="info-row">
                        <span class="key"><i class="fas fa-code-branch" style="margin-right:5px;"></i>Default Branch</span>
                        <code style="color:var(--green);font-size:12px;"><?= h($repo['default_branch'] ?? 'main') ?></code>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-calendar-plus" style="margin-right:5px;"></i>Created</span>
                        <span class="value" style="font-size:12px;"><?= date('M j, Y', strtotime($repo['created_at'] ?? 'now')) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-clock" style="margin-right:5px;"></i>Updated</span>
                        <span class="value" style="font-size:12px;"><?= timeAgo($repo['updated_at'] ?? null) ?></span>
                    </div>
                    <?php if (!empty($repo['license']['name'])): ?>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-balance-scale" style="margin-right:5px;"></i>License</span>
                        <span class="value" style="font-size:12px;"><?= h($repo['license']['name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($repo['language'] ?? ''): ?>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-code" style="margin-right:5px;"></i>Language</span>
                        <span style="display:flex;align-items:center;gap:6px;font-size:12px;">
                            <span class="lang-dot" style="background:<?= langColor($repo['language']) ?>;"></span>
                            <?= h($repo['language']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Branches widget -->
            <?php if (!empty($branches)): ?>
            <div class="repo-sidebar-widget">
                <div class="w-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <span><i class="fas fa-code-branch" style="margin-right:5px;color:var(--green);"></i>Branches</span>
                    <span style="font-size:11px;font-weight:600;color:var(--text-muted);"><?= count($branches) ?></span>
                </div>
                <div class="w-body" style="padding:6px 8px;">
                    <?php foreach (array_slice($branches, 0, 10) as $branch): ?>
                    <div class="branch-item">
                        <a href="<?= h(giteaLink($repo['full_name'] . '/src/branch/' . urlencode($branch['name']))) ?>" target="_blank"
                           style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--blue);text-decoration:none;">
                            <?= h($branch['name']) ?>
                        </a>
                        <?php if (($branch['name'] ?? '') === ($repo['default_branch'] ?? '')): ?>
                        <span class="badge badge-public" style="font-size:10px;">default</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($branches) > 10): ?>
                    <div style="padding:6px 8px;font-size:12px;color:var(--text-muted);">+<?= count($branches) - 10 ?> more</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Releases widget -->
            <?php if (!empty($releases)): ?>
            <div class="repo-sidebar-widget">
                <div class="w-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <span><i class="fas fa-tag" style="margin-right:5px;color:var(--orange);"></i>Releases</span>
                    <span style="font-size:11px;font-weight:600;color:var(--text-muted);"><?= count($releases) ?></span>
                </div>
                <div>
                    <?php foreach (array_slice($releases, 0, 5) as $release): ?>
                    <div class="release-item">
                        <a href="<?= h($release['html_url'] ?? '') ?>" target="_blank"
                           style="font-size:13px;font-weight:600;color:var(--blue);text-decoration:none;display:block;margin-bottom:3px;">
                            <?= h($release['name'] ?? $release['tag_name'] ?? '') ?>
                        </a>
                        <div style="font-size:11px;color:var(--text-muted);">
                            <code style="color:var(--orange);"><?= h($release['tag_name'] ?? '') ?></code>
                            &nbsp;·&nbsp;<?= timeAgo($release['created_at'] ?? date('c')) ?>
                        </div>
                        <?php if (!empty($release['assets'])): ?>
                        <div style="margin-top:6px;display:flex;flex-direction:column;gap:3px;">
                            <?php foreach (array_slice($release['assets'], 0, 3) as $asset): ?>
                            <a href="?page=download&type=release&url=<?= urlencode($asset['browser_download_url'] ?? '') ?>&filename=<?= urlencode($asset['name'] ?? 'download') ?>"
                               class="btn btn-ghost" style="font-size:11px;padding:3px 8px;justify-content:flex-start;gap:5px;width:100%;">
                                <i class="fas fa-download" style="color:var(--blue);"></i>
                                <?= h(mb_strimwidth($asset['name'] ?? '', 0, 28, '…')) ?>
                                <span style="margin-left:auto;color:var(--text-muted);"><?= round(($asset['size'] ?? 0) / 1024, 0) ?> KB</span>
                            </a>
                            <?php endforeach; ?>
                            <?php if (count($release['assets']) > 3): ?>
                            <a href="<?= h($release['html_url'] ?? '') ?>" target="_blank" style="font-size:11px;color:var(--blue);text-decoration:none;padding:2px 8px;">
                                +<?= count($release['assets']) - 3 ?> more assets →
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <!-- No assets — offer archive download for this tag -->
                        <div style="margin-top:6px;display:flex;gap:4px;">
                            <a href="?page=download&type=archive&owner=<?= urlencode($repo['owner']['login'] ?? '') ?>&repo=<?= urlencode($repo['name'] ?? '') ?>&ref=<?= urlencode($release['tag_name'] ?? 'main') ?>&format=zip"
                               class="btn btn-ghost" style="font-size:11px;padding:3px 8px;gap:4px;">
                                <i class="fas fa-file-zipper" style="color:var(--blue);"></i> ZIP
                            </a>
                            <a href="?page=download&type=archive&owner=<?= urlencode($repo['owner']['login'] ?? '') ?>&repo=<?= urlencode($repo['name'] ?? '') ?>&ref=<?= urlencode($release['tag_name'] ?? 'main') ?>&format=tar.gz"
                               class="btn btn-ghost" style="font-size:11px;padding:3px 8px;gap:4px;">
                                <i class="fas fa-file-code" style="color:var(--green);"></i> TAR
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tags widget -->
            <?php if (!empty($tags)): ?>
            <div class="repo-sidebar-widget">
                <div class="w-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <span><i class="fas fa-hashtag" style="margin-right:5px;color:var(--purple);"></i>Tags</span>
                    <span style="font-size:11px;font-weight:600;color:var(--text-muted);"><?= count($tags) ?></span>
                </div>
                <div class="w-body" style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach (array_slice($tags, 0, 12) as $tag): ?>
                    <a href="<?= h(giteaLink($repo['full_name'] . '/src/tag/' . urlencode($tag['name'] ?? ''))) ?>" target="_blank" class="tag-chip">
                        <?= h($tag['name'] ?? '') ?>
                    </a>
                    <?php endforeach; ?>
                    <?php if (count($tags) > 12): ?>
                    <span style="font-size:12px;color:var(--text-muted);align-self:center;">+<?= count($tags)-12 ?> more</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.right-col -->
        </div><!-- /.repo-detail-grid -->

        <?php endif; // repo exists ?>

        <!-- ====================================================
             USERS PAGE
             ==================================================== -->
        <?php elseif ($page === 'users'):
        $users = $pageData['users'] ?? [];
        $totalUsers = $pageData['total_users'] ?? count($users);
        ?>

        <!-- Page title -->
        <div class="page-title-row">
            <div class="page-title">
                <h1><i class="fas fa-users" style="color:var(--blue);margin-right:10px;"></i>Users
                    <span style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:8px;"><?= $totalUsers ?> total</span>
                </h1>
                <p>All registered users at <?= h(APP_DOMAIN) ?></p>
            </div>
            <a href="<?= h(giteaLink('-/admin/users')) ?>" target="_blank" class="btn btn-secondary">
                <i class="fas fa-user-cog"></i> Admin Panel
            </a>
        </div>

        <?php if (empty($users)): ?>
        <div class="empty-state card">
            <div class="es-icon"><i class="fas fa-users"></i></div>
            <h3>No users found</h3>
            <p>No users accessible via API<?= $giteaToken ? '' : ' — try adding an admin token in Settings' ?>.</p>
            <?php if (!$giteaToken): ?><a href="?page=settings" class="btn btn-secondary" style="margin-top:14px;">Configure Token</a><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="grid-users">
            <?php foreach ($users as $user): ?>
            <div class="user-card">
                <?php if (!empty($user['avatar_url'])): ?>
                <img src="<?= h($user['avatar_url']) ?>" alt="<?= h($user['login']) ?>" class="user-avatar">
                <?php else: ?>
                <div class="avatar-placeholder user-avatar" style="font-size:22px;"><?= strtoupper(substr($user['login'] ?? 'U', 0, 1)) ?></div>
                <?php endif; ?>
                <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;text-align:center;">
                    <a href="?page=user&username=<?= urlencode($user['login'] ?? '') ?>" style="color:var(--text-primary);text-decoration:none;">
                        <?= h($user['login'] ?? '') ?>
                    </a>
                </div>
                <?php if (!empty($user['full_name'])): ?>
                <div style="font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;text-align:center;"><?= h($user['full_name']) ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:6px;margin-top:6px;width:100%;justify-content:center;">
                    <a href="?page=user&username=<?= urlencode($user['login'] ?? '') ?>" class="btn btn-blue btn-sm">
                        <i class="fas fa-code-branch"></i> Repos
                    </a>
                    <a href="<?= h(giteaLink($user['login'])) ?>" target="_blank" class="btn btn-ghost btn-sm">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ====================================================
             USER PROFILE PAGE
             ==================================================== -->
        <?php elseif ($page === 'user'):
        $user  = $pageData['user'] ?? null;
        $repos = $pageData['repos'] ?? [];
        $orgs  = $pageData['orgs'] ?? [];
        ?>

        <?php if (!$user): ?>
        <div class="alert alert-error"><i class="fas fa-times-circle"></i> User not found.</div>
        <?php else: ?>

        <div class="user-profile-grid">
            <!-- Left: Profile card -->
            <div>
                <!-- Avatar -->
                <?php if (!empty($user['avatar_url'])): ?>
                <img src="<?= h($user['avatar_url']) ?>" alt="" style="width:100%;max-width:220px;border-radius:50%;border:3px solid var(--border-color);display:block;margin:0 auto 16px;">
                <?php else: ?>
                <div class="avatar-placeholder" style="width:100%;max-width:220px;aspect-ratio:1;border-radius:50%;font-size:64px;margin:0 auto 16px;">
                    <?= strtoupper(substr($user['login'] ?? 'U', 0, 1)) ?>
                </div>
                <?php endif; ?>

                <div style="text-align:center;margin-bottom:16px;">
                    <h1 style="font-size:20px;font-weight:700;margin:0 0 4px;"><?= h($user['full_name'] ?? $user['login']) ?></h1>
                    <p style="color:var(--text-muted);font-size:14px;margin:0;">@<?= h($user['login']) ?></p>
                </div>

                <?php if (!empty($user['description'])): ?>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;line-height:1.6;text-align:center;"><?= h($user['description']) ?></p>
                <?php endif; ?>

                <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
                    <?php if (!empty($user['location'])): ?>
                    <div style="display:flex;align-items:center;gap:8px;"><i class="fas fa-map-marker-alt" style="width:14px;color:var(--text-muted);"></i><?= h($user['location']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($user['email'])): ?>
                    <div style="display:flex;align-items:center;gap:8px;"><i class="fas fa-envelope" style="width:14px;color:var(--text-muted);"></i><?= h($user['email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($user['website'])): ?>
                    <div style="display:flex;align-items:center;gap:8px;"><i class="fas fa-link" style="width:14px;color:var(--text-muted);"></i><a href="<?= h($user['website']) ?>" target="_blank" style="color:var(--blue);"><?= h($user['website']) ?></a></div>
                    <?php endif; ?>
                    <?php if (!empty($user['created'])): ?>
                    <div style="display:flex;align-items:center;gap:8px;"><i class="fas fa-calendar" style="width:14px;color:var(--text-muted);"></i>Joined <?= date('M Y', strtotime($user['created'])) ?></div>
                    <?php endif; ?>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px;">
                    <a href="<?= h(giteaLink($user['login'])) ?>" target="_blank" class="btn btn-secondary" style="justify-content:center;">
                        <i class="fas fa-external-link-alt"></i> View on Gitea
                    </a>
                </div>

                <?php if (!empty($orgs)): ?>
                <div style="margin-top:20px;">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;font-weight:700;">Organizations</div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($orgs as $org): ?>
                        <a href="?page=org&org=<?= urlencode($org['username'] ?? '') ?>" title="<?= h($org['username'] ?? '') ?>">
                            <?php if (!empty($org['avatar_url'])): ?>
                            <img src="<?= h($org['avatar_url']) ?>" alt="" style="width:32px;height:32px;border-radius:6px;border:1px solid var(--border-color);">
                            <?php else: ?>
                            <div class="avatar-placeholder" style="width:32px;height:32px;font-size:13px;border-radius:6px;"><?= strtoupper(substr($org['username'] ?? 'O', 0, 1)) ?></div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div><!-- /.profile-left -->

            <!-- Right: Repos grid -->
            <div>
                <h2 style="font-size:16px;font-weight:600;margin:0 0 16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-code-branch" style="color:var(--green);"></i>
                    Repositories
                    <span style="font-size:13px;color:var(--text-muted);font-weight:400;">(<?= count($repos) ?>)</span>
                </h2>
                <?php if (empty($repos)): ?>
                <div class="empty-state card">
                    <div class="es-icon"><i class="fas fa-folder-open"></i></div>
                    <h3>No repositories</h3>
                    <p>No public repositories found.</p>
                </div>
                <?php else: ?>
                <div class="grid-repos">
                    <?php foreach ($repos as $r): ?>
                    <div class="repo-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                            <a href="?page=repo&owner=<?= urlencode($r['owner']['login'] ?? '') ?>&repo=<?= urlencode($r['name']) ?>" class="repo-name">
                                <?= h($r['name']) ?>
                            </a>
                            <?= visibility_badge($r['private'] ?? false, $r['fork'] ?? false) ?>
                        </div>
                        <?php if (!empty($r['description'])): ?>
                        <p class="repo-desc"><?= h($r['description']) ?></p>
                        <?php endif; ?>
                        <div class="repo-meta">
                            <?php if (!empty($r['language'])): ?>
                            <span><span class="lang-dot" style="background:<?= langColor($r['language']) ?>;"></span><?= h($r['language']) ?></span>
                            <?php endif; ?>
                            <?php if (($r['stars_count']??0)>0): ?><span style="color:var(--yellow);"><i class="fas fa-star"></i> <?= $r['stars_count'] ?></span><?php endif; ?>
                            <span style="margin-left:auto;"><?= timeAgo($r['updated_at'] ?? $r['created_at'] ?? null) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div><!-- /.repos-right -->
        </div><!-- /.user-profile-grid -->
        <?php endif; // user exists ?>

        <!-- ====================================================
             ORGANIZATIONS PAGE
             ==================================================== -->
        <?php elseif ($page === 'orgs'):
        $orgs = $pageData['orgs'] ?? [];
        $totalOrgs = $pageData['total_orgs'] ?? count($orgs);
        ?>

        <div class="page-title-row">
            <div class="page-title">
                <h1><i class="fas fa-building" style="color:var(--orange);margin-right:10px;"></i>Organizations
                    <span style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:8px;"><?= $totalOrgs ?> total</span>
                </h1>
                <p>Organizations at <?= h(APP_DOMAIN) ?></p>
            </div>
            <a href="<?= h(giteaLink('org/create')) ?>" target="_blank" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Organization
            </a>
        </div>

        <?php if (empty($orgs)): ?>
        <div class="empty-state card">
            <div class="es-icon"><i class="fas fa-building"></i></div>
            <h3>No organizations found</h3>
            <p><?= $giteaToken ? 'No organizations accessible.' : 'Admin token required to list organizations.' ?></p>
            <?php if (!$giteaToken): ?><a href="?page=settings" class="btn btn-secondary" style="margin-top:14px;">Configure Token</a><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="grid-users">
            <?php foreach ($orgs as $org): ?>
            <div class="user-card">
                <?php if (!empty($org['avatar_url'])): ?>
                <img src="<?= h($org['avatar_url']) ?>" alt="" class="user-avatar" style="border-radius:var(--radius-lg);">
                <?php else: ?>
                <div class="avatar-placeholder user-avatar" style="font-size:22px;border-radius:var(--radius-lg);"><?= strtoupper(substr($org['username'] ?? $org['name'] ?? 'O', 0, 1)) ?></div>
                <?php endif; ?>
                <div style="font-weight:600;font-size:14px;width:100%;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <a href="?page=org&org=<?= urlencode($org['username'] ?? '') ?>" style="color:var(--text-primary);text-decoration:none;">
                        <?= h($org['full_name'] ?? $org['username'] ?? '') ?>
                    </a>
                </div>
                <div style="font-size:12px;color:var(--text-muted);">@<?= h($org['username'] ?? '') ?></div>
                <?php if (!empty($org['description'])): ?>
                <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;text-align:center;"><?= h($org['description']) ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:6px;margin-top:6px;justify-content:center;width:100%;">
                    <a href="?page=org&org=<?= urlencode($org['username'] ?? '') ?>" class="btn btn-blue btn-sm">
                        <i class="fas fa-info-circle"></i> Details
                    </a>
                    <a href="<?= h(giteaLink($org['username'] ?? '')) ?>" target="_blank" class="btn btn-ghost btn-sm">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ====================================================
             ORG DETAIL PAGE
             ==================================================== -->
        <?php elseif ($page === 'org'):
        $org   = $pageData['org'] ?? null;
        $repos = $pageData['repos'] ?? [];
        ?>

        <?php if (!$org): ?>
        <div class="alert alert-error"><i class="fas fa-times-circle"></i> Organization not found.</div>
        <?php else: ?>

        <!-- Org Header -->
        <div class="repo-header" style="margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:16px;flex:1;min-width:0;">
                    <?php if (!empty($org['avatar_url'])): ?>
                    <img src="<?= h($org['avatar_url']) ?>" alt="" style="width:64px;height:64px;border-radius:var(--radius-lg);border:2px solid var(--border-color);flex-shrink:0;">
                    <?php else: ?>
                    <div class="avatar-placeholder" style="width:64px;height:64px;font-size:28px;border-radius:var(--radius-lg);flex-shrink:0;"><?= strtoupper(substr($org['username'] ?? 'O', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <h1 style="font-size:22px;font-weight:700;margin:0 0 4px;"><?= h($org['full_name'] ?? $org['username']) ?></h1>
                        <p style="color:var(--text-muted);font-size:14px;margin:0;">@<?= h($org['username'] ?? '') ?></p>
                        <?php if (!empty($org['description'])): ?>
                        <p style="font-size:14px;color:var(--text-secondary);margin:8px 0 0;line-height:1.5;"><?= h($org['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?= h(giteaLink($org['username'] ?? '')) ?>" target="_blank" class="btn btn-secondary" style="flex-shrink:0;">
                    <i class="fas fa-external-link-alt"></i> Open in Gitea
                </a>
            </div>
        </div>

        <!-- Repos -->
        <h2 style="font-size:16px;font-weight:600;margin:0 0 16px;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-code-branch" style="color:var(--green);"></i>
            Repositories
            <span style="font-size:13px;color:var(--text-muted);font-weight:400;">(<?= count($repos) ?>)</span>
        </h2>

        <?php if (empty($repos)): ?>
        <div class="empty-state card">
            <div class="es-icon"><i class="fas fa-folder-open"></i></div>
            <h3>No repositories</h3>
            <p>No repositories in this organization.</p>
        </div>
        <?php else: ?>
        <div class="grid-repos">
            <?php foreach ($repos as $r): ?>
            <div class="repo-card">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <a href="?page=repo&owner=<?= urlencode($r['owner']['login'] ?? '') ?>&repo=<?= urlencode($r['name']) ?>" class="repo-name">
                        <?= h($r['name']) ?>
                    </a>
                    <?= visibility_badge($r['private'] ?? false, $r['fork'] ?? false) ?>
                </div>
                <?php if (!empty($r['description'])): ?>
                <p class="repo-desc"><?= h($r['description']) ?></p>
                <?php endif; ?>
                <div class="repo-meta">
                    <?php if (!empty($r['language'])): ?>
                    <span><span class="lang-dot" style="background:<?= langColor($r['language']) ?>;"></span><?= h($r['language']) ?></span>
                    <?php endif; ?>
                    <?php if (($r['stars_count']??0)>0): ?><span style="color:var(--yellow);"><i class="fas fa-star"></i> <?= $r['stars_count'] ?></span><?php endif; ?>
                    <span style="margin-left:auto;"><?= timeAgo($r['updated_at'] ?? $r['created_at'] ?? null) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; // org exists ?>

        <!-- ====================================================
             SETTINGS PAGE
             ==================================================== -->
        <?php elseif ($page === 'settings'):
        $settingsMsg = $_GET['msg'] ?? '';
        ?>

        <div style="max-width:680px;">
            <!-- Page title -->
            <div class="page-title" style="margin-bottom:24px;">
                <h1><i class="fas fa-gear" style="color:var(--text-muted);margin-right:10px;"></i>Settings</h1>
                <p>Konfigurasi dan informasi sesi Gitea Manager</p>
            </div>

            <?php if ($settingsMsg === 'saved'): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> Pengaturan berhasil disimpan.</div>
            <?php elseif ($settingsMsg === 'cache_cleared'): ?>
            <div class="alert alert-success"><i class="fas fa-broom"></i> Cache berhasil dibersihkan.</div>
            <?php endif; ?>

            <!-- ── Active Session ───────────────────────────────── -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <span><i class="fas fa-id-badge" style="color:var(--green);margin-right:8px;"></i>Sesi Aktif</span>
                    <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--green);">
                        <span style="width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 5px var(--green);display:inline-block;"></span>
                        Online
                    </span>
                </div>
                <div class="card-body" style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;">
                    <!-- Avatar -->
                    <div style="flex-shrink:0;text-align:center;">
                        <?php if (!empty($currentUser['avatar_url'])): ?>
                        <img src="<?= h($currentUser['avatar_url']) ?>" alt=""
                             style="width:72px;height:72px;border-radius:50%;border:3px solid rgba(35,134,54,.4);">
                        <?php else: ?>
                        <div class="avatar-placeholder" style="width:72px;height:72px;font-size:28px;border-radius:50%;">
                            <?= strtoupper(substr($currentUser['login'] ?? 'U', 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($currentUser['is_admin'] ?? false): ?>
                        <div style="margin-top:6px;" class="badge badge-admin">ADMIN</div>
                        <?php endif; ?>
                    </div>
                    <!-- Info -->
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:18px;font-weight:700;margin-bottom:4px;"><?= h($currentUser['login'] ?? '') ?></div>
                        <?php if (!empty($currentUser['full_name'])): ?>
                        <div style="color:var(--text-secondary);font-size:14px;margin-bottom:10px;"><?= h($currentUser['full_name']) ?></div>
                        <?php endif; ?>
                        <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:var(--text-muted);">
                            <?php if (!empty($currentUser['email'])): ?>
                            <span><i class="fas fa-envelope" style="margin-right:4px;"></i><?= h($currentUser['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($_SESSION['login_time'])): ?>
                            <span><i class="fas fa-clock" style="margin-right:4px;"></i>Login <?= timeAgo(date('c', $_SESSION['login_time'])) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-server" style="margin-right:4px;"></i><?= h($giteaUrl) ?></span>
                        </div>
                    </div>
                    <!-- Action buttons -->
                    <div style="display:flex;flex-direction:column;gap:8px;flex-shrink:0;">
                        <a href="<?= h(giteaLink($currentUser['login'] ?? '')) ?>" target="_blank" class="btn btn-secondary btn-sm">
                            <i class="fas fa-external-link-alt"></i> Lihat Profil
                        </a>
                        <a href="<?= h(giteaLink('user/settings/applications')) ?>" target="_blank" class="btn btn-blue btn-sm">
                            <i class="fas fa-key"></i> Kelola Token
                        </a>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="btn btn-danger btn-sm" style="width:100%;justify-content:center;">
                                <i class="fas fa-right-from-bracket"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
                <!-- Token preview -->
                <div style="padding:12px 18px;border-top:1px solid var(--border-color);">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;">Token Aktif</div>
                    <div class="clone-box" style="font-size:12px;">
                        <i class="fas fa-key" style="color:var(--yellow);flex-shrink:0;"></i>
                        <code id="token-preview"><?= str_repeat('•', 20) . '···' . substr(h($giteaToken), -6) ?></code>
                        <button class="copy-btn" onclick="revealToken(this)" id="revealBtn">
                            <i class="fas fa-eye"></i> Lihat
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Connection Status ────────────────────────────── -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <span><i class="fas fa-plug" style="color:var(--blue);margin-right:8px;"></i>Status Koneksi</span>
                </div>
                <div class="card-body">
                    <?php if ($siteVersion): ?>
                    <div class="alert alert-success" style="margin:0;">
                        <i class="fas fa-circle-check"></i>
                        Terhubung ke <strong><?= h($giteaUrl) ?></strong> &mdash; Gitea <strong><?= h($siteVersion['version'] ?? 'unknown') ?></strong>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-error" style="margin:0;">
                        <i class="fas fa-circle-xmark"></i>
                        Tidak dapat terhubung ke <strong><?= h($giteaUrl) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Preferences ──────────────────────────────────── -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <span><i class="fas fa-sliders" style="color:var(--orange);margin-right:8px;"></i>Preferensi</span>
                </div>
                <form method="POST" class="card-body">
                    <input type="hidden" name="action" value="save_config">
                    <div class="form-group">
                        <label class="form-label">Repositories per halaman</label>
                        <select name="per_page" class="form-input" style="width:auto;">
                            <?php foreach ([10, 20, 30, 50] as $n): ?>
                            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> repo</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Jumlah repository yang ditampilkan per halaman dashboard</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-floppy-disk"></i> Simpan
                    </button>
                </form>
            </div>

            <!-- ── Cache Management ─────────────────────────────── -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <span><i class="fas fa-database" style="color:var(--purple);margin-right:8px;"></i>Cache API</span>
                </div>
                <div class="card-body">
                    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;line-height:1.6;">
                        Respons API di-cache selama <strong><?= CACHE_DURATION/60 ?> menit</strong> untuk mengurangi beban server. Bersihkan jika perlu data terbaru.
                    </p>
                    <?php
                    $cacheDir   = sys_get_temp_dir() . '/gitea_cache';
                    $cacheFiles = glob($cacheDir . '/*.json');
                    $cacheCount = $cacheFiles ? count($cacheFiles) : 0;
                    $cacheSize  = 0;
                    if ($cacheFiles) foreach ($cacheFiles as $f) $cacheSize += filesize($f);
                    ?>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
                        <div class="stat-card" style="padding:14px;">
                            <div class="stat-value" style="font-size:20px;"><?= $cacheCount ?></div>
                            <div class="stat-label">Cache Items</div>
                        </div>
                        <div class="stat-card" style="padding:14px;">
                            <div class="stat-value" style="font-size:20px;"><?= round($cacheSize/1024, 1) ?> KB</div>
                            <div class="stat-label">Total Size</div>
                        </div>
                        <div class="stat-card" style="padding:14px;">
                            <div class="stat-value" style="font-size:20px;"><?= CACHE_DURATION/60 ?>m</div>
                            <div class="stat-label">TTL</div>
                        </div>
                    </div>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="clear_cache">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-broom"></i> Bersihkan Cache
                        </button>
                    </form>
                </div>
            </div>

            <!-- ── Danger Zone ───────────────────────────────────── -->
            <div class="card" style="border-color:rgba(248,81,73,.3);">
                <div class="card-header" style="border-bottom-color:rgba(248,81,73,.2);">
                    <span style="color:var(--red);"><i class="fas fa-triangle-exclamation" style="margin-right:8px;"></i>Zona Bahaya</span>
                </div>
                <div class="card-body">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div>
                            <div style="font-size:14px;font-weight:600;margin-bottom:3px;">Akhiri Sesi</div>
                            <div style="font-size:13px;color:var(--text-muted);">Logout dan hapus semua data sesi dari browser.</div>
                        </div>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-right-from-bracket"></i> Logout Sekarang
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ====================================================
             FILE PAGE — BROWSER / VIEWER / EDITOR / NEW
             ==================================================== -->
        <?php elseif ($page === 'file'):
        $fInfo    = $pageData['file_info']    ?? null;
        $fAction  = $pageData['file_action']  ?? '';
        $fOwner   = $pageData['file_owner']   ?? '';
        $fRepo    = $pageData['file_repo']    ?? '';
        $fPath    = $pageData['file_path']    ?? '';
        $fRef     = $pageData['file_ref']     ?? '';
        $fType    = $pageData['file_type']    ?? 'file';
        $fContent = $pageData['file_content'] ?? '';
        $fTree    = $pageData['file_tree']    ?? [];
        $repoInfo = $pageData['repo_info']    ?? [];
        $branches = $pageData['branches']     ?? [];
        $fName    = basename($fPath);
        $fDir     = dirname($fPath); $fDir = ($fDir === '.') ? '' : $fDir;
        $fSha     = $fInfo['sha'] ?? '';
        $fSizeFmt = isset($fInfo['size']) ? ($fInfo['size']>=1024 ? round($fInfo['size']/1024,1).' KB' : $fInfo['size'].' B') : '';
        $fEncoding= $fInfo['encoding'] ?? '';
        $isBinary = ($fEncoding === 'base64') && !$isImage && preg_match('/\x00/', $fContent ?? '');
        // Large files: Gitea returns null content — treat as binary to show download prompt
        if (!$isImage && !empty($fInfo['size']) && $fInfo['size'] > 1048576 && $fContent === '') {
            $isBinary = true;
        }
        $ext      = strtolower(pathinfo($fPath, PATHINFO_EXTENSION));
        $isImage  = in_array($ext, ['png','jpg','jpeg','gif','svg','webp','ico','bmp']);
        $isText   = !$isBinary && !$isImage;
        $repoBack = '?page=repo&owner='.urlencode($fOwner).'&repo='.urlencode($fRepo).'&ref='.urlencode($fRef).'&path='.urlencode($fDir);

        // Flash messages
        if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">
            <i class="fas fa-circle-check"></i> <?= h($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); endif;
        if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:14px;">
            <i class="fas fa-triangle-exclamation"></i> <?= h($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); endif; ?>

        <!-- ── Breadcrumb ─────────────────────────────────────── -->
        <div class="breadcrumb" style="margin-bottom:14px;">
            <a href="?page=repos"><i class="fas fa-code-branch" style="margin-right:4px;"></i>Repos</a>
            <span class="sep">/</span>
            <a href="?page=repo&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>"><?= h($fOwner.'/'.$fRepo) ?></a>
            <?php
            $cumPath = '';
            foreach (($fPath !== '' ? explode('/', $fPath) : []) as $pi => $part):
                $cumPath = $cumPath !== '' ? $cumPath.'/'.$part : $part;
            ?>
            <span class="sep">/</span>
            <?php if ($cumPath !== $fPath): ?>
            <a href="?page=file&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($cumPath) ?>&ref=<?= urlencode($fRef) ?>"><?= h($part) ?></a>
            <?php else: ?>
            <strong style="color:var(--text-primary);"><?= h($part) ?></strong>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($fAction === 'new'): /* ══════════ NEW FILE FORM ══════════ */ ?>

        <div class="card" style="max-width:900px;">
            <div class="card-header">
                <span><i class="fas fa-file-circle-plus" style="color:var(--green);margin-right:8px;"></i>
                    Buat File Baru — <code style="color:var(--blue);font-size:12px;"><?= h($fOwner.'/'.$fRepo) ?></code>
                </span>
            </div>
            <div class="card-body">
            <form method="POST" action="?page=file&action=save&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>">
                <input type="hidden" name="is_new" value="1">
                <input type="hidden" name="sha" value="">
                <!-- Path input -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Path & Nama File</label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <code style="color:var(--text-muted);font-size:13px;white-space:nowrap;"><?= h($fOwner.'/'.$fRepo . ($fPath ? '/'.$fPath : '')) ?>/</code>
                        <input type="text" name="path" placeholder="nama-file.txt" required
                               value="<?= h($fPath !== '' ? $fPath.'/' : '') ?>"
                               class="form-input" style="font-family:'JetBrains Mono',monospace;font-size:13px;flex:1;" id="newFilePath">
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Gunakan <code>/</code> untuk membuat direktori sekaligus, contoh: <code>src/utils/helper.php</code></div>
                </div>
                <!-- Branch -->
                <div style="margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:160px;">
                        <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Branch</label>
                        <select name="branch" class="form-input" style="width:100%;">
                            <?php foreach ($branches as $br): ?>
                            <option value="<?= h($br['name']) ?>" <?= ($br['name'] === ($repoInfo['default_branch'] ?? 'main')) ? 'selected' : '' ?>><?= h($br['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:2;min-width:220px;">
                        <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Commit Message</label>
                        <input type="text" name="message" class="form-input" placeholder="Create new file" style="width:100%;">
                    </div>
                </div>
                <!-- Editor -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Konten File</label>
                    <div id="editorWrap" style="border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;">
                        <div id="codeEditor" style="min-height:360px;font-size:13px;"></div>
                    </div>
                    <textarea name="content" id="editorContent" style="display:none;"></textarea>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Commit File Baru</button>
                    <a href="?page=repo&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&ref=<?= urlencode($fRef) ?>&path=<?= urlencode($fPath) ?>" class="btn btn-secondary"><i class="fas fa-xmark"></i> Batal</a>
                </div>
            </form>
            </div>
        </div>
        <script>document.addEventListener('DOMContentLoaded', function(){ initEditor('', ''); });</script>

        <?php elseif ($fAction === 'edit' && in_array($fType, ['file', 'symlink', ''])): /* ══ EDITOR ══ */ ?>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <h2 style="font-size:16px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-pen" style="color:var(--blue);"></i>
                    Edit: <code style="color:var(--blue);font-weight:400;font-size:14px;"><?= h($fPath) ?></code>
                </h2>
                <?php if ($fSizeFmt): ?><span style="font-size:12px;color:var(--text-muted);"><?= $fSizeFmt ?></span><?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;">
                <a href="?page=file&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>"
                   class="btn btn-ghost"><i class="fas fa-eye"></i> View</a>
                <a href="<?= h($repoBack) ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <form method="POST" action="?page=file&action=save&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>" id="editForm">
            <input type="hidden" name="is_new"  value="0">
            <input type="hidden" name="sha"     value="<?= h($fSha) ?>">
            <input type="hidden" name="path"    value="<?= h($fPath) ?>">
            <!-- Toolbar -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;background:var(--bg-secondary);padding:10px 14px;border:1px solid var(--border-color);border-radius:var(--radius);">
                <select name="branch" class="form-input" style="font-size:12px;padding:3px 8px;width:auto;min-width:110px;">
                    <?php foreach ($branches as $br): ?>
                    <option value="<?= h($br['name']) ?>" <?= ($br['name'] === $fRef) ? 'selected' : '' ?>><?= h($br['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="message" class="form-input" placeholder="Update <?= h(basename($fPath)) ?>"
                       style="flex:1;min-width:200px;font-size:13px;" value="Update <?= h(basename($fPath)) ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Commit</button>
                <!-- Word wrap toggle -->
                <button type="button" onclick="toggleWrap()" class="btn btn-ghost btn-sm" id="btnWrap" title="Toggle word wrap">
                    <i class="fas fa-align-left"></i>
                </button>
                <!-- Delete button -->
                <button type="button" onclick="confirmDelete()" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <!-- Editor area -->
            <div id="editorWrap" style="border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px;">
                <div id="editorLineInfo" style="background:var(--bg-tertiary);padding:4px 12px;font-size:11px;color:var(--text-muted);display:flex;gap:16px;border-bottom:1px solid var(--border-color);">
                    <span id="editorLang" style="color:var(--green);"><?= strtoupper($ext ?: 'TEXT') ?></span>
                    <span id="cursorPos">Ln 1, Col 1</span>
                    <span id="lineCount"><?= substr_count($fContent, "\n") + 1 ?> lines</span>
                    <span><?= $fSizeFmt ?></span>
                </div>
                <div id="codeEditor" style="height:520px;font-size:13px;"></div>
            </div>
            <textarea name="content" id="editorContent" style="display:none;"><?= h($fContent) ?></textarea>
        </form>

        <!-- Delete confirm modal -->
        <div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center;">
            <div style="background:var(--bg-secondary);border:1px solid rgba(248,81,73,.4);border-radius:var(--radius-lg);padding:28px;max-width:420px;width:90%;">
                <h3 style="color:var(--red);margin:0 0 12px;font-size:16px;"><i class="fas fa-triangle-exclamation"></i> Hapus File?</h3>
                <p style="font-size:13px;color:var(--text-secondary);margin:0 0 18px;"><code><?= h($fPath) ?></code> akan dihapus permanen dari repository.</p>
                <form method="POST" action="?page=file&action=delete&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>">
                    <input type="hidden" name="path"   value="<?= h($fPath) ?>">
                    <input type="hidden" name="sha"    value="<?= h($fSha) ?>">
                    <input type="hidden" name="branch" value="<?= h($fRef) ?>">
                    <input type="text"   name="message" class="form-input" value="Delete <?= h(basename($fPath)) ?>" style="width:100%;margin-bottom:14px;font-size:13px;">
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Ya, Hapus</button>
                        <button type="button" onclick="document.getElementById('deleteModal').style.display='none'" class="btn btn-secondary">Batal</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            initEditor(<?= json_encode($fContent) ?>, <?= json_encode($ext) ?>);
        });
        function confirmDelete(){
            var m = document.getElementById('deleteModal');
            m.style.display = m.style.display==='flex' ? 'none' : 'flex';
        }
        </script>

        <?php else: /* ══════════════════════ FILE VIEW / DIR ══════════════════ */ ?>

        <?php if ($fType === 'dir' || empty($fInfo)): /* ─── DIRECTORY ─── */ ?>
        <div class="card" style="padding:0;overflow:hidden;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <span><i class="fas fa-folder-open" style="color:var(--yellow);margin-right:8px;"></i>
                    <?= h($fPath ?: $fRepo) ?>
                </span>
                <a href="?page=file&action=new&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>"
                   class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New File</a>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <?php foreach ($fTree as $item):
                    $iType = $item['type']??'file'; $iName=$item['name']??''; $iPath=$item['path']??$iName;
                    $isDir = $iType==='dir';
                    $iHref = $isDir
                        ? '?page=file&owner='.urlencode($fOwner).'&repo='.urlencode($fRepo).'&path='.urlencode($iPath).'&ref='.urlencode($fRef)
                        : '?page=file&owner='.urlencode($fOwner).'&repo='.urlencode($fRepo).'&path='.urlencode($iPath).'&ref='.urlencode($fRef);
                    $iExt=strtolower(pathinfo($iName,PATHINFO_EXTENSION));
                    $iIcon='fas fa-file'; $iColor='var(--text-muted)';
                    if($isDir){$iIcon='fas fa-folder';$iColor='var(--yellow)';}
                    elseif(in_array($iExt,['php','js','ts','py','rb','go','java','c','cpp'])){$iIcon='fas fa-file-code';$iColor='var(--blue)';}
                    elseif(in_array($iExt,['md','txt'])){$iIcon='fas fa-file-lines';$iColor='var(--text-secondary)';}
                    elseif(in_array($iExt,['png','jpg','jpeg','gif','svg'])){$iIcon='fas fa-file-image';$iColor='var(--purple)';}
                ?>
                <tr style="border-bottom:1px solid var(--border-color);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background=''">
                    <td style="padding:8px 14px;width:26px;"><i class="<?= $iIcon ?>" style="color:<?= $iColor ?>;font-size:13px;"></i></td>
                    <td style="padding:8px 4px;"><a href="<?= h($iHref) ?>" style="color:<?= $isDir?'var(--text-primary)':'var(--blue)' ?>;font-size:13px;text-decoration:none;font-weight:<?= $isDir?'500':'400' ?>;"><?= h($iName) ?></a></td>
                    <td style="padding:8px 14px;max-width:260px;overflow:hidden;">
                        <?php
                        $fCommitMsg  = $item['_commit_message'] ?? '';
                        $fCommitSha  = $item['_commit_sha']     ?? '';
                        $fCommitUrl  = $item['_commit_url']     ?? '#';
                        $fCommitDate = $item['last_committer_date'] ?? $item['last_author_date'] ?? null;
                        if ($fCommitMsg !== ''):
                        ?>
                        <a href="<?= h($fCommitUrl) ?>" target="_blank"
                           style="color:var(--text-secondary);font-size:12px;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:240px;"
                           title="<?= h($fCommitMsg) ?>">
                            <?= h(mb_strimwidth($fCommitMsg, 0, 52, '…')) ?>
                        </a>
                        <div style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                            <?php if ($fCommitSha !== ''): ?>
                            <code style="font-size:10px;color:var(--purple);font-family:'JetBrains Mono',monospace;opacity:.9;"><?= h($fCommitSha) ?></code>
                            <?php endif; ?>
                            <?php if ($fCommitDate): ?>
                            <span style="font-size:11px;color:var(--text-muted);" title="<?= h($fCommitDate) ?>">
                                <?= timeAgo($fCommitDate) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($fCommitDate): ?>
                        <span style="font-size:12px;color:var(--text-muted);" title="<?= h($fCommitDate) ?>">
                            <?= timeAgo($fCommitDate) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 12px;text-align:right;white-space:nowrap;">
                        <?php if (!$isDir): ?>
                        <a href="?page=file&action=edit&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($iPath) ?>&ref=<?= urlencode($fRef) ?>" class="btn btn-ghost" style="font-size:11px;padding:2px 7px;"><i class="fas fa-pen"></i></a>
                        <a href="?page=download&type=raw&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($iPath) ?>&ref=<?= urlencode($fRef) ?>" class="btn btn-ghost" style="font-size:11px;padding:2px 7px;"><i class="fas fa-download"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php elseif ($isImage): /* ─── IMAGE VIEWER ─── */ ?>
        <div class="card" style="max-width:860px;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <span><i class="fas fa-file-image" style="color:var(--purple);margin-right:8px;"></i><?= h($fName) ?></span>
                <div style="display:flex;gap:6px;">
                    <a href="?page=download&type=raw&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Download</a>
                    <a href="<?= h($repoBack) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i></a>
                </div>
            </div>
            <div class="card-body" style="text-align:center;background:var(--bg-tertiary);padding:20px;">
                <?php
                $rawUrl = rtrim($_SESSION['gitea_url'] ?? '', '/')
                        . '/' . $fOwner . '/' . $fRepo . '/raw/branch/' . ($fRef?:($repoInfo['default_branch']??'main'))
                        . '/' . $fPath;
                ?>
                <img src="<?= h($rawUrl) ?>" alt="<?= h($fName) ?>"
                     style="max-width:100%;max-height:600px;border-radius:var(--radius);border:1px solid var(--border-color);">
            </div>
            <div style="padding:10px 16px;font-size:12px;color:var(--text-muted);border-top:1px solid var(--border-color);display:flex;gap:16px;">
                <span><i class="fas fa-file-image" style="margin-right:4px;"></i><?= h($fName) ?></span>
                <?php if ($fSizeFmt): ?><span><?= $fSizeFmt ?></span><?php endif; ?>
                <span><code style="color:var(--green);"><?= h($fRef) ?></code></span>
            </div>
        </div>

        <?php elseif ($isBinary): /* ─── BINARY FILE ─── */ ?>
        <div class="card" style="max-width:720px;">
            <div class="card-header">
                <span><i class="fas fa-file-circle-question" style="color:var(--orange);margin-right:8px;"></i><?= h($fName) ?></span>
            </div>
            <div class="card-body" style="text-align:center;padding:40px 24px;">
                <div style="font-size:48px;margin-bottom:14px;">📦</div>
                <p style="color:var(--text-muted);margin:0 0 16px;">File biner — tidak bisa ditampilkan sebagai teks.</p>
                <a href="?page=download&type=raw&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>"
                   class="btn btn-primary"><i class="fas fa-download"></i> Download File</a>
            </div>
        </div>

        <?php else: /* ─── TEXT / CODE VIEWER ─── */ ?>
        <div style="max-width:1000px;">
            <!-- File header bar -->
            <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius) var(--radius) 0 0;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:10px;font-size:13px;flex-wrap:wrap;">
                    <span style="color:var(--text-primary);font-weight:500;"><?= h($fName) ?></span>
                    <?php if ($fSizeFmt): ?><span style="color:var(--text-muted);"><?= $fSizeFmt ?></span><?php endif; ?>
                    <span style="color:var(--text-muted);"><?= substr_count($fContent, "\n") + 1 ?> lines</span>
                    <?php if ($fSha): ?><code class="commit-sha" style="font-size:11px;"><?= substr($fSha,0,8) ?></code><?php endif; ?>
                    <code style="background:rgba(63,185,80,.12);color:var(--green);padding:1px 7px;border-radius:10px;font-size:11px;"><?= h($fRef) ?></code>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button onclick="toggleViewWrap()" class="btn btn-ghost btn-sm" title="Toggle word wrap"><i class="fas fa-align-left"></i></button>
                    <button onclick="copyFileContent()" class="btn btn-ghost btn-sm" id="btnCopyFile"><i class="fas fa-copy"></i> Copy</button>
                    <a href="?page=file&action=edit&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>"
                       class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i> Edit</a>
                    <a href="?page=download&type=raw&owner=<?= urlencode($fOwner) ?>&repo=<?= urlencode($fRepo) ?>&path=<?= urlencode($fPath) ?>&ref=<?= urlencode($fRef) ?>"
                       class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> Raw</a>
                    <a href="<?= h($repoBack) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i></a>
                </div>
            </div>
            <!-- Code display -->
            <div id="codeViewWrap" style="border:1px solid var(--border-color);border-top:none;border-radius:0 0 var(--radius) var(--radius);overflow:auto;background:var(--bg-primary);">
                <div id="codeViewInner" style="display:flex;">
                    <!-- Line numbers -->
                    <div id="lineNums" style="padding:14px 10px 14px 14px;text-align:right;color:var(--text-muted);font-size:12px;font-family:'JetBrains Mono',monospace;line-height:1.7;user-select:none;min-width:50px;border-right:1px solid var(--border-color);background:var(--bg-secondary);flex-shrink:0;">
                        <?php
                        $lineCount = substr_count($fContent, "\n") + 1;
                        for ($li = 1; $li <= $lineCount; $li++): ?>
                        <div id="L<?= $li ?>"><?= $li ?></div>
                        <?php endfor; ?>
                    </div>
                    <!-- Highlighted code -->
                    <div style="flex:1;overflow:auto;">
                        <pre id="codeBlock" style="margin:0;padding:14px 18px;font-size:12px;font-family:'JetBrains Mono',monospace;line-height:1.7;white-space:pre;tab-size:4;color:var(--text-primary);"><code id="codeContent" class="language-<?= h($ext) ?>"><?= h($fContent) ?></code></pre>
                    </div>
                </div>
            </div>
            <div id="rawContent" style="display:none;"><?= h($fContent) ?></div>
        </div>
        <script>
        // Syntax highlight via Prism
        document.addEventListener('DOMContentLoaded', function(){
            if (window.Prism) Prism.highlightAll();
        });
        function toggleViewWrap(){
            var pre = document.getElementById('codeBlock');
            pre.style.whiteSpace = pre.style.whiteSpace === 'pre-wrap' ? 'pre' : 'pre-wrap';
        }
        function copyFileContent(){
            var text = document.getElementById('rawContent').textContent;
            navigator.clipboard.writeText(text).then(function(){
                var btn = document.getElementById('btnCopyFile');
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(function(){ btn.innerHTML = orig; }, 2000);
            });
        }
        // Highlight line from URL hash
        if (location.hash.startsWith('#L')) {
            var el = document.getElementById(location.hash.slice(1));
            if (el) { el.style.background='rgba(255,211,61,.2)'; el.scrollIntoView({block:'center'}); }
        }
        </script>

        <?php endif; // image/binary/text ?>
        <?php endif; // fAction ?>

        <!-- ====================================================
             ACTIVITY / LIVE FEED PAGE
             ==================================================== -->
        <?php elseif ($page === 'activity'):
        $events = $pageData['events'] ?? [];
        ?>

        <!-- Page title -->
        <div class="page-title-row" style="margin-bottom:16px;">
            <div class="page-title">
                <h1 style="display:flex;align-items:center;gap:12px;">
                    <i class="fas fa-bolt" style="color:var(--yellow);"></i>
                    Activity Feed
                    <span id="liveIndicator" style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:400;color:var(--green);opacity:0;transition:opacity .4s;">
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block;animation:livePulse 1.4s ease-in-out infinite;"></span>
                        Live
                    </span>
                </h1>
                <p>Real-time activity across all repositories — pushes, releases, and more.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <!-- Live Stats Bar -->
                <div id="liveStatsBar" style="display:flex;gap:6px;flex-wrap:wrap;">
                    <span class="stat-pill" id="ls-repos" style="cursor:default;"><i class="fas fa-code-branch" style="color:var(--green);"></i> <strong id="ls-repos-val">…</strong> <span>repos</span></span>
                    <span class="stat-pill" id="ls-stars" style="cursor:default;"><i class="fas fa-star" style="color:var(--yellow);"></i> <strong id="ls-stars-val">…</strong> <span>stars</span></span>
                    <span class="stat-pill" id="ls-issues" style="cursor:default;"><i class="fas fa-circle-dot" style="color:var(--red);"></i> <strong id="ls-issues-val">…</strong> <span>issues</span></span>
                </div>
                <button onclick="manualRefreshActivity()" class="btn btn-secondary" id="btnRefresh">
                    <i class="fas fa-rotate-right"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Notification banner -->
        <div id="activityNotice" style="display:none;background:rgba(63,185,80,.12);border:1px solid rgba(63,185,80,.35);border-radius:var(--radius);padding:8px 14px;margin-bottom:14px;font-size:13px;color:var(--green);display:flex;align-items:center;gap:8px;">
            <i class="fas fa-circle-check"></i>
            <span id="activityNoticeText">Connected to live stream.</span>
            <button onclick="this.parentElement.style.display='none'" style="margin-left:auto;background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;font-size:14px;">✕</button>
        </div>

        <!-- Two-column layout: feed + sidebar -->
        <div style="display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start;">

        <!-- LEFT: Activity feed list -->
        <div>
            <div class="card" style="padding:0;overflow:hidden;">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <span><i class="fas fa-list-ul" style="color:var(--blue);margin-right:8px;"></i>Recent Events
                        <span id="eventCount" style="font-size:12px;font-weight:400;color:var(--text-muted);margin-left:6px;"><?= count($events) ?> loaded</span>
                    </span>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <span id="lastUpdated" style="font-size:11px;color:var(--text-muted);"></span>
                        <select id="filterType" onchange="applyActivityFilter()" class="form-input" style="font-size:12px;padding:3px 8px;width:auto;">
                            <option value="">All types</option>
                            <option value="push">Pushes</option>
                            <option value="release">Releases</option>
                        </select>
                    </div>
                </div>
                <div id="activityFeed" style="max-height:600px;overflow-y:auto;">
                    <?php if (empty($events)): ?>
                    <div class="empty-state" style="padding:40px 20px;">
                        <div class="icon"><i class="fas fa-satellite-dish"></i></div>
                        <h3>No recent activity</h3>
                        <p>Activity will appear here once events are detected.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($events as $ev): ?>
                    <div class="activity-item" data-type="<?= h($ev['type'] ?? 'push') ?>">
                        <div class="act-avatar">
                            <?php if (!empty($ev['avatar_url'])): ?>
                            <img src="<?= h($ev['avatar_url']) ?>" alt="" style="width:32px;height:32px;border-radius:50%;border:2px solid var(--border-color);">
                            <?php else: ?>
                            <div style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--text-muted);">
                                <?= strtoupper(substr($ev['actor'] ?? 'U', 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <span class="act-type-icon act-<?= h($ev['type'] ?? 'push') ?>">
                                <?php if (($ev['type'] ?? '') === 'release'): ?>
                                <i class="fas fa-tag"></i>
                                <?php else: ?>
                                <i class="fas fa-code-commit"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="act-body">
                            <div class="act-headline">
                                <a href="?page=user&username=<?= urlencode($ev['actor'] ?? '') ?>" class="act-actor"><?= h($ev['actor'] ?? 'Unknown') ?></a>
                                <?php if (($ev['type'] ?? '') === 'release'): ?>
                                <span class="act-verb">released</span>
                                <a href="<?= h($ev['url'] ?? '#') ?>" target="_blank" class="act-ref"><?= h($ev['message'] ?? '') ?></a>
                                <span class="act-verb">in</span>
                                <?php else: ?>
                                <span class="act-verb">pushed to</span>
                                <?php endif; ?>
                                <a href="?page=repo&owner=<?= urlencode($ev['repo_owner'] ?? '') ?>&repo=<?= urlencode($ev['repo_name'] ?? '') ?>" class="act-repo">
                                    <?= h($ev['repo'] ?? '') ?>
                                </a>
                                <?php if ($ev['private'] ?? false): ?>
                                <span class="badge badge-private" style="font-size:10px;">private</span>
                                <?php endif; ?>
                            </div>
                            <?php if (($ev['type'] ?? '') === 'push' && !empty($ev['message'])): ?>
                            <div class="act-message"><?= h(mb_strimwidth($ev['message'], 0, 120, '…')) ?></div>
                            <?php if (!empty($ev['sha'])): ?>
                            <a href="<?= h($ev['url'] ?? '#') ?>" target="_blank" class="act-sha"><?= h(substr($ev['sha'], 0, 7)) ?></a>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="act-time"><?= timeAgo($ev['created_at'] ?? null) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /.left -->

        <!-- RIGHT: Live stats sidebar -->
        <div>
            <!-- Connection status -->
            <div class="repo-sidebar-widget" id="sseStatusWidget">
                <div class="w-header"><i class="fas fa-satellite-dish" style="margin-right:5px;color:var(--green);"></i>Live Connection</div>
                <div class="w-body">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span id="sseStatus" style="width:10px;height:10px;border-radius:50%;background:var(--text-muted);display:inline-block;transition:background .3s;flex-shrink:0;"></span>
                        <span id="sseStatusText" style="font-size:13px;color:var(--text-muted);">Connecting…</span>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);display:flex;flex-direction:column;gap:4px;">
                        <div>Updates every <strong style="color:var(--text-secondary);">30 s</strong> via SSE</div>
                        <div>Polling fallback every <strong style="color:var(--text-secondary);">60 s</strong></div>
                        <div id="sseReconnects" style="display:none;">Reconnects: <strong id="sseReconnectCount">0</strong></div>
                    </div>
                </div>
            </div>

            <!-- Live stats counters -->
            <div class="repo-sidebar-widget">
                <div class="w-header"><i class="fas fa-chart-bar" style="margin-right:5px;color:var(--blue);"></i>Live Stats</div>
                <div class="w-body" style="display:flex;flex-direction:column;gap:8px;">
                    <div class="info-row">
                        <span class="key"><i class="fas fa-code-branch" style="margin-right:5px;color:var(--green);"></i>Repos</span>
                        <strong id="stat-repos" style="color:var(--text-primary);font-size:15px;">…</strong>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-lock" style="margin-right:5px;color:var(--orange);"></i>Private</span>
                        <strong id="stat-private" style="color:var(--orange);font-size:15px;">…</strong>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-globe" style="margin-right:5px;color:var(--blue);"></i>Public</span>
                        <strong id="stat-public" style="color:var(--blue);font-size:15px;">…</strong>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-star" style="margin-right:5px;color:var(--yellow);"></i>Stars</span>
                        <strong id="stat-stars" style="color:var(--yellow);font-size:15px;">…</strong>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-code-fork" style="margin-right:5px;color:var(--purple);"></i>Forks</span>
                        <strong id="stat-forks" style="color:var(--purple);font-size:15px;">…</strong>
                    </div>
                    <div class="info-row">
                        <span class="key"><i class="fas fa-circle-dot" style="margin-right:5px;color:var(--red);"></i>Issues</span>
                        <strong id="stat-issues" style="color:var(--red);font-size:15px;">…</strong>
                    </div>
                    <div style="margin-top:4px;font-size:11px;color:var(--text-muted);border-top:1px solid var(--border-color);padding-top:8px;">
                        Last update: <span id="stat-ts">—</span>
                    </div>
                </div>
            </div>

            <!-- Quick filters -->
            <div class="repo-sidebar-widget">
                <div class="w-header"><i class="fas fa-filter" style="margin-right:5px;color:var(--purple);"></i>Filter Events</div>
                <div class="w-body" style="display:flex;flex-direction:column;gap:6px;">
                    <button onclick="applyActivityFilter('','',this)" class="btn btn-secondary btn-sm filter-btn active" data-ftype="" style="justify-content:flex-start;">
                        <i class="fas fa-list-ul"></i> All Events <span id="fc-all" class="nav-badge" style="margin-left:auto;"></span>
                    </button>
                    <button onclick="applyActivityFilter('push','',this)" class="btn btn-ghost btn-sm filter-btn" data-ftype="push" style="justify-content:flex-start;">
                        <i class="fas fa-code-commit" style="color:var(--blue);"></i> Pushes <span id="fc-push" class="nav-badge" style="margin-left:auto;"></span>
                    </button>
                    <button onclick="applyActivityFilter('release','',this)" class="btn btn-ghost btn-sm filter-btn" data-ftype="release" style="justify-content:flex-start;">
                        <i class="fas fa-tag" style="color:var(--orange);"></i> Releases <span id="fc-release" class="nav-badge" style="margin-left:auto;"></span>
                    </button>
                </div>
            </div>
        </div><!-- /.right -->
        </div><!-- /.two-col -->

        <!-- Activity page CSS -->
        <style>
        @keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.3)} }
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            transition: background .15s;
        }
        .activity-item:hover { background: var(--bg-hover); }
        .activity-item:last-child { border-bottom: none; }
        .activity-item.act-new { animation: actHighlight .8s ease-out; }
        @keyframes actHighlight { 0%{background:rgba(63,185,80,.18)} 100%{background:transparent} }
        .act-avatar { position: relative; flex-shrink: 0; }
        .act-type-icon {
            position: absolute; bottom: -3px; right: -3px;
            width: 16px; height: 16px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 8px; border: 2px solid var(--bg-secondary);
        }
        .act-push { background: var(--blue); color: #fff; }
        .act-release { background: var(--orange); color: #fff; }
        .act-body { flex: 1; min-width: 0; }
        .act-headline { font-size: 13px; line-height: 1.5; flex-wrap: wrap; display: flex; gap: 4px; align-items: baseline; }
        .act-actor { color: var(--blue); font-weight: 600; text-decoration: none; }
        .act-actor:hover { text-decoration: underline; }
        .act-verb { color: var(--text-muted); }
        .act-repo { color: var(--green); font-weight: 600; text-decoration: none; }
        .act-repo:hover { text-decoration: underline; }
        .act-ref { color: var(--orange); font-weight: 600; text-decoration: none; }
        .act-ref:hover { text-decoration: underline; }
        .act-message { font-size: 12px; color: var(--text-secondary); margin-top: 4px; font-family: 'JetBrains Mono', monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
        .act-sha { font-size: 11px; color: var(--purple); font-family: 'JetBrains Mono', monospace; text-decoration: none; margin-top: 3px; display: inline-block; }
        .act-sha:hover { text-decoration: underline; }
        .act-time { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        .filter-btn.active { background: var(--bg-tertiary) !important; border-color: var(--border-active) !important; color: var(--text-primary) !important; }
        @media (max-width:768px) {
            [style*="grid-template-columns:1fr 280px"] { grid-template-columns: 1fr !important; }
        }
        </style>

        <script>
        // Activity page specific JS — initialised after DOM ready
        document.addEventListener('DOMContentLoaded', function() { initActivityPage(); });
        </script>

        <?php endif; // end switch pages ?>

    </main>
</div><!-- /.main-content -->
</div><!-- /.app-shell -->

<script>
/* ── Sidebar toggle ───────────────────────────────────────── */
function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('overlay');
    const open = sb.classList.toggle('open');
    ov.style.display = open ? 'block' : 'none';
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').style.display = 'none';
}

/* ── Copy to clipboard ───────────────────────────────────── */
function copyText(elementId, btn) {
    const text = document.getElementById(elementId).textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('copied'); }, 2000);
    });
}

/* ── Reveal token in settings ────────────────────────────── */
let tokenRevealed = false;
const REAL_TOKEN = <?= json_encode($giteaToken) ?>;
function revealToken(btn) {
    const el = document.getElementById('token-preview');
    tokenRevealed = !tokenRevealed;
    if (tokenRevealed) {
        el.textContent = REAL_TOKEN;
        btn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide';
        btn.classList.add('copied');
    } else {
        el.textContent = '•'.repeat(20) + REAL_TOKEN.slice(-6);
        btn.innerHTML = '<i class="fas fa-eye"></i> Show';
        btn.classList.remove('copied');
    }
}

/* ── Auto-dismiss alerts ─────────────────────────────────── */
document.querySelectorAll('.alert').forEach(a => {
    if (!a.querySelector('a')) {
        setTimeout(() => {
            a.style.transition = 'opacity .5s, max-height .5s';
            a.style.opacity = '0'; a.style.maxHeight = '0'; a.style.overflow = 'hidden';
            setTimeout(() => a.remove(), 500);
        }, 5000);
    }
});

/* ── Keyboard shortcut: / focuses search ─────────────────── */
document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
        e.preventDefault();
        const s = document.querySelector('.topnav-search input');
        if (s) s.focus();
    }
    if (e.key === 'Escape') closeSidebar();
});

console.log('%c🐱 <?= h(APP_NAME) ?> v<?= APP_VERSION ?>', 'color:#3fb950;font-weight:700;font-size:14px;');
</script>

<!-- ═══════════════════════════════════════════════════════════════
     DOWNLOAD DROPDOWN + REAL-TIME / SSE JAVASCRIPT
     ════════════════════════════════════════════════════════════ -->
<style>
/* Download dropdown */
.dl-menu-item:hover { background: var(--bg-hover) !important; }
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.3)} }
/* File row hover */
.file-row:hover td { background: var(--bg-hover); }
</style>
<script>
/* ── Monaco Editor initialiser ───────────────────────────────── */
var _monacoEditor = null;

function initEditor(initialContent, lang) {
    if (typeof require === 'undefined') return; // Monaco not loaded
    var extToLang = {
        'js':'javascript','ts':'typescript','tsx':'typescript','jsx':'javascript',
        'php':'php','py':'python','rb':'ruby','go':'go','java':'java',
        'c':'c','cpp':'cpp','cs':'csharp','swift':'swift','kt':'kotlin','rs':'rust',
        'html':'html','htm':'html','css':'css','scss':'scss','sass':'scss','less':'less',
        'json':'json','xml':'xml','yaml':'yaml','yml':'yaml','toml':'ini','md':'markdown',
        'sh':'shell','bash':'shell','zsh':'shell','sql':'sql','lua':'lua',
        'vue':'html','svelte':'html','env':'ini','ini':'ini','conf':'ini','cfg':'ini',
    };
    var monacoLang = extToLang[lang] || 'plaintext';

    require.config({ paths: { 'vs': 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
    require(['vs/editor/editor.main'], function() {
        // Dark theme matching our UI
        monaco.editor.defineTheme('giteaDark', {
            base: 'vs-dark',
            inherit: true,
            rules: [],
            colors: {
                'editor.background': '#0d1117',
                'editor.foreground': '#e6edf3',
                'editorLineNumber.foreground': '#6e7681',
                'editorLineNumber.activeForeground': '#e6edf3',
                'editor.selectionBackground': '#264f78',
                'editor.lineHighlightBackground': '#161b22',
                'editorCursor.foreground': '#58a6ff',
                'editor.inactiveSelectionBackground': '#1c2128',
                'editorIndentGuide.background': '#21262d',
                'editorWidget.background': '#161b22',
                'editorSuggestWidget.background': '#161b22',
                'editorSuggestWidget.border': '#30363d',
            }
        });
        monaco.editor.setTheme('giteaDark');

        var container = document.getElementById('codeEditor');
        _monacoEditor = monaco.editor.create(container, {
            value: initialContent,
            language: monacoLang,
            theme: 'giteaDark',
            fontSize: 13,
            fontFamily: "'JetBrains Mono', 'Fira Code', monospace",
            fontLigatures: true,
            lineHeight: 22,
            minimap: { enabled: true, scale: 2 },
            wordWrap: 'off',
            scrollBeyondLastLine: false,
            renderLineHighlight: 'all',
            bracketPairColorization: { enabled: true },
            autoIndent: 'advanced',
            formatOnPaste: false,
            tabSize: 4,
            insertSpaces: true,
            automaticLayout: true,
            scrollbar: { verticalScrollbarSize: 8, horizontalScrollbarSize: 8 },
        });

        // Sync to hidden textarea on save
        var form = container.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                var ta = document.getElementById('editorContent');
                if (ta) ta.value = _monacoEditor.getValue();
            });
        }

        // Update cursor position
        _monacoEditor.onDidChangeCursorPosition(function(e) {
            var el = document.getElementById('cursorPos');
            if (el) el.textContent = 'Ln ' + e.position.lineNumber + ', Col ' + e.position.column;
            var lc = document.getElementById('lineCount');
            if (lc) lc.textContent = _monacoEditor.getModel().getLineCount() + ' lines';
        });

        // File path changes language on new-file page
        var pathEl = document.getElementById('newFilePath');
        if (pathEl) {
            pathEl.addEventListener('input', function() {
                var newExt = this.value.split('.').pop().toLowerCase();
                var newLang = extToLang[newExt] || 'plaintext';
                monaco.editor.setModelLanguage(_monacoEditor.getModel(), newLang);
                var langEl = document.getElementById('editorLang');
                if (langEl) langEl.textContent = newExt.toUpperCase() || 'TEXT';
            });
        }
    });
}

function toggleWrap() {
    if (!_monacoEditor) return;
    var cur = _monacoEditor.getOption(monaco.editor.EditorOption.wordWrap);
    _monacoEditor.updateOptions({ wordWrap: cur === 'off' ? 'on' : 'off' });
}

/* ── Download dropdown toggle ─────────────────────────────────── */
function toggleDlMenu(btn) {
    var menu = btn.nextElementSibling;
    var isOpen = menu.style.display !== 'none';
    // Close all other open menus
    document.querySelectorAll('.dl-menu').forEach(function(m){ m.style.display='none'; });
    menu.style.display = isOpen ? 'none' : 'block';
    if (!isOpen) {
        // Close on outside click
        setTimeout(function(){
            document.addEventListener('click', function closeDl(e){
                if (!btn.closest('.dl-dropdown').contains(e.target)) {
                    menu.style.display='none';
                    document.removeEventListener('click', closeDl);
                }
            });
        }, 10);
    }
}

/* ══════════════════════════════════════════════════════════════════
   REAL-TIME / ACTIVITY PAGE ENGINE
   ══════════════════════════════════════════════════════════════ */

var RT = {
    sse: null,
    pollTimer: null,
    statsTimer: null,
    reconnects: 0,
    maxReconnects: 20,
    sseRetryDelay: 3000,
    pollInterval: 60000,      // 60s polling fallback
    statsInterval: 30000,     // 30s stats poll
    onActivityPage: false,
    knownEventKeys: new Set(),

    init: function() {
        RT.onActivityPage = (document.getElementById('activityFeed') !== null);

        // Always poll live stats on dashboard (for stat counters in topnav)
        RT.startStatsPolling();

        if (RT.onActivityPage) {
            RT.connectSSE();
            RT.startActivityPolling();
            RT.updateFilterCounts();
        }
    },

    /* ── SSE Connection ─────────────────────────────────────── */
    connectSSE: function() {
        if (RT.sse) { try { RT.sse.close(); } catch(e){} }
        RT.setStatus('connecting', 'Connecting…');

        var src = new EventSource('?page=sse&type=activity');
        RT.sse = src;

        src.addEventListener('heartbeat', function(e) {
            RT.setStatus('connected', 'Connected — live');
            RT.showLive(true);
        });

        src.addEventListener('activity', function(e) {
            try {
                var events = JSON.parse(e.data);
                RT.renderActivityFeed(events, true);
                RT.updateLastUpdated();
            } catch(ex) {}
        });

        src.addEventListener('stats', function(e) {
            try { RT.applyStats(JSON.parse(e.data)); } catch(ex) {}
        });

        src.addEventListener('end', function(e) {
            // Server ended stream gracefully — reconnect after short delay
            src.close();
            RT.scheduleReconnect(RT.sseRetryDelay);
        });

        src.onerror = function() {
            src.close();
            RT.setStatus('error', 'Reconnecting…');
            RT.showLive(false);
            if (RT.reconnects < RT.maxReconnects) {
                RT.reconnects++;
                RT.updateReconnectCount();
                var delay = Math.min(RT.sseRetryDelay * Math.pow(1.5, Math.min(RT.reconnects, 6)), 60000);
                RT.scheduleReconnect(delay);
            } else {
                RT.setStatus('error', 'Offline — polling only');
            }
        };
    },

    scheduleReconnect: function(delay) {
        setTimeout(function() { RT.connectSSE(); }, delay);
    },

    /* ── Stats polling (all pages) ──────────────────────────── */
    startStatsPolling: function() {
        RT.fetchStats();
        RT.statsTimer = setInterval(RT.fetchStats, RT.statsInterval);
    },

    fetchStats: function() {
        fetch('?page=api&action=live_stats')
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){ if(d) RT.applyStats(d); })
            .catch(function(){});
    },

    applyStats: function(d) {
        var map = {
            'stat-repos':    d.repos,
            'stat-private':  d.private,
            'stat-public':   d.public,
            'stat-stars':    d.stars,
            'stat-forks':    d.forks,
            'stat-issues':   d.issues,
            'ls-repos-val':  d.repos,
            'ls-stars-val':  d.stars,
            'ls-issues-val': d.issues,
        };
        Object.keys(map).forEach(function(id){
            var el = document.getElementById(id);
            if (el && map[id] !== undefined) {
                var newVal = RT.fmt(map[id]);
                if (el.textContent !== newVal) {
                    el.textContent = newVal;
                    el.style.transition = 'color .3s';
                    el.style.color = 'var(--green)';
                    setTimeout(function(){ el.style.color = ''; }, 800);
                }
            }
        });
        // Update stat-ts
        var tsEl = document.getElementById('stat-ts');
        if (tsEl && d.ts) {
            var ago = RT.timeAgoJs(d.ts * 1000);
            tsEl.textContent = ago;
        }
    },

    /* ── Activity polling fallback ──────────────────────────── */
    startActivityPolling: function() {
        RT.pollTimer = setInterval(function(){
            fetch('?page=api&action=activity&limit=20')
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(events){ if(events && Array.isArray(events)) RT.renderActivityFeed(events, false); })
                .catch(function(){});
        }, RT.pollInterval);
    },

    /* ── Manual refresh button ─────────────────────────────── */
    // (exposed as global below)

    /* ── Render activity feed ───────────────────────────────── */
    renderActivityFeed: function(events, highlightNew) {
        if (!Array.isArray(events) || events.length === 0) return;
        var feed = document.getElementById('activityFeed');
        if (!feed) return;

        // Remove empty-state placeholder if present
        var empty = feed.querySelector('.empty-state');
        if (empty) empty.remove();

        var inserted = 0;
        events.forEach(function(ev) {
            var key = (ev.type || 'push') + '|' + (ev.repo || '') + '|' + (ev.sha || ev.created_at || '');
            if (RT.knownEventKeys.has(key)) return;
            RT.knownEventKeys.add(key);

            var item = RT.buildEventItem(ev, highlightNew);

            // Insert at top
            if (feed.firstChild) {
                feed.insertBefore(item, feed.firstChild);
            } else {
                feed.appendChild(item);
            }
            inserted++;
        });

        // Cap feed at 60 items
        var items = feed.querySelectorAll('.activity-item');
        if (items.length > 60) {
            for (var i = 60; i < items.length; i++) { items[i].remove(); }
        }

        // Update event count badge
        var countEl = document.getElementById('eventCount');
        if (countEl) countEl.textContent = feed.querySelectorAll('.activity-item').length + ' events';

        // Update filter counts
        RT.updateFilterCounts();

        // Show notice if new items
        if (inserted > 0 && highlightNew) {
            RT.showNotice(inserted + ' new event' + (inserted > 1 ? 's' : '') + ' received.');
        }

        RT.updateLastUpdated();
    },

    buildEventItem: function(ev, highlight) {
        var div = document.createElement('div');
        div.className = 'activity-item' + (highlight ? ' act-new' : '');
        div.setAttribute('data-type', ev.type || 'push');

        var avatarHtml = ev.avatar_url
            ? '<img src="' + RT.esc(ev.avatar_url) + '" alt="" style="width:32px;height:32px;border-radius:50%;border:2px solid var(--border-color);">'
            : '<div style="width:32px;height:32px;border-radius:50%;background:var(--bg-tertiary);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--text-muted);">'
              + RT.esc((ev.actor || 'U').charAt(0).toUpperCase()) + '</div>';

        var typeIcon = ev.type === 'release'
            ? '<i class="fas fa-tag"></i>'
            : '<i class="fas fa-code-commit"></i>';

        var typeClass = 'act-' + (ev.type || 'push');

        var verbLine = '';
        if (ev.type === 'release') {
            verbLine = '<span class="act-verb">released</span> '
                + '<a href="' + RT.esc(ev.url || '#') + '" target="_blank" class="act-ref">' + RT.esc(ev.message || '') + '</a> '
                + '<span class="act-verb">in</span>';
        } else {
            verbLine = '<span class="act-verb">pushed to</span>';
        }

        var repoOwner = ev.repo_owner || (ev.repo ? ev.repo.split('/')[0] : '');
        var repoName  = ev.repo_name  || (ev.repo ? ev.repo.split('/')[1] : ev.repo || '');

        var msgHtml = '';
        if (ev.type !== 'release' && ev.message) {
            var msgShort = ev.message.length > 120 ? ev.message.substring(0,120) + '…' : ev.message;
            msgHtml = '<div class="act-message">' + RT.esc(msgShort) + '</div>';
            if (ev.sha) {
                msgHtml += '<a href="' + RT.esc(ev.url || '#') + '" target="_blank" class="act-sha">' + ev.sha.substring(0,7) + '</a>';
            }
        }

        var privateBadge = ev.private ? '<span class="badge badge-private" style="font-size:10px;">private</span>' : '';

        div.innerHTML = '<div class="act-avatar">'
            + avatarHtml
            + '<span class="act-type-icon ' + typeClass + '">' + typeIcon + '</span>'
            + '</div>'
            + '<div class="act-body">'
            +   '<div class="act-headline">'
            +     '<a href="?page=user&username=' + encodeURIComponent(ev.actor || '') + '" class="act-actor">' + RT.esc(ev.actor || 'Unknown') + '</a>'
            +     verbLine
            +     '<a href="?page=repo&owner=' + encodeURIComponent(repoOwner) + '&repo=' + encodeURIComponent(repoName) + '" class="act-repo">'
            +       RT.esc(ev.repo || '') + '</a>'
            +     privateBadge
            +   '</div>'
            +   msgHtml
            +   '<div class="act-time">' + RT.timeAgoJs(new Date(ev.created_at || Date.now()).getTime()) + '</div>'
            + '</div>';

        return div;
    },

    /* ── Filter ─────────────────────────────────────────────── */
    // (exposed as global below)

    updateFilterCounts: function() {
        var feed = document.getElementById('activityFeed');
        if (!feed) return;
        var all     = feed.querySelectorAll('.activity-item').length;
        var pushes  = feed.querySelectorAll('.activity-item[data-type="push"]').length;
        var releases= feed.querySelectorAll('.activity-item[data-type="release"]').length;
        RT.setText('fc-all',     all     || '');
        RT.setText('fc-push',    pushes  || '');
        RT.setText('fc-release', releases|| '');
    },

    /* ── Status helpers ─────────────────────────────────────── */
    setStatus: function(state, text) {
        var dot  = document.getElementById('sseStatus');
        var label= document.getElementById('sseStatusText');
        if (!dot || !label) return;
        var colors = { connected:'var(--green)', connecting:'var(--yellow)', error:'var(--red)' };
        dot.style.background   = colors[state] || 'var(--text-muted)';
        dot.style.animation    = state === 'connected' ? 'livePulse 1.4s ease-in-out infinite' : 'none';
        label.textContent      = text;
        label.style.color      = state === 'connected' ? 'var(--green)' : state === 'error' ? 'var(--red)' : 'var(--yellow)';
    },

    showLive: function(on) {
        var el = document.getElementById('liveIndicator');
        if (el) el.style.opacity = on ? '1' : '0';
    },

    showNotice: function(msg) {
        var n = document.getElementById('activityNotice');
        var t = document.getElementById('activityNoticeText');
        if (!n || !t) return;
        t.textContent = msg;
        n.style.display = 'flex';
        clearTimeout(RT._noticeTimer);
        RT._noticeTimer = setTimeout(function(){ n.style.display='none'; }, 5000);
    },

    updateLastUpdated: function() {
        var el = document.getElementById('lastUpdated');
        if (el) el.textContent = 'Updated ' + RT.timeAgoJs(Date.now());
    },

    updateReconnectCount: function() {
        var el = document.getElementById('sseReconnectCount');
        var row= document.getElementById('sseReconnects');
        if (el) { el.textContent = RT.reconnects; if(row) row.style.display=''; }
    },

    /* ── Utilities ──────────────────────────────────────────── */
    esc: function(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },
    fmt: function(n) {
        if (n === undefined || n === null) return '…';
        if (n >= 1000) return (n/1000).toFixed(1) + 'k';
        return String(n);
    },
    setText: function(id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
    },
    timeAgoJs: function(ts) {
        var now = Date.now();
        var ms  = now - (typeof ts === 'number' ? ts : new Date(ts).getTime());
        var s   = Math.floor(ms / 1000);
        if (s < 10)  return 'just now';
        if (s < 60)  return s + 's ago';
        var m = Math.floor(s / 60);
        if (m < 60)  return m + 'm ago';
        var h = Math.floor(m / 60);
        if (h < 24)  return h + 'h ago';
        var d = Math.floor(h / 24);
        return d + 'd ago';
    }
};

/* ── Global functions (called from HTML onclick) ─────────────── */
function initActivityPage() {
    RT.init();
}

function manualRefreshActivity() {
    var btn = document.getElementById('btnRefresh');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Loading…'; }

    Promise.all([
        fetch('?page=api&action=activity&limit=30').then(function(r){ return r.ok?r.json():null; }),
        fetch('?page=api&action=live_stats').then(function(r){ return r.ok?r.json():null; })
    ]).then(function(results) {
        if (results[0] && Array.isArray(results[0])) RT.renderActivityFeed(results[0], true);
        if (results[1]) RT.applyStats(results[1]);
    }).finally(function(){
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rotate-right"></i> Refresh';
        }
    });
}

function applyActivityFilter(type, search, clickedBtn) {
    // Sync dropdown select
    var sel = document.getElementById('filterType');
    if (sel && type !== undefined) sel.value = type || '';

    var filterType = type !== undefined ? type : (sel ? sel.value : '');

    var items = document.querySelectorAll('#activityFeed .activity-item');
    items.forEach(function(item){
        var t = item.getAttribute('data-type') || '';
        item.style.display = (!filterType || t === filterType) ? '' : 'none';
    });

    // Update active state on sidebar filter buttons
    document.querySelectorAll('.filter-btn').forEach(function(b){
        b.classList.toggle('active', (b.getAttribute('data-ftype') || '') === filterType);
        b.className = b.className.replace(/\bbtn-secondary\b/g, 'btn-ghost').replace(/\bbtn-ghost\b/g, 'btn-ghost');
        if ((b.getAttribute('data-ftype') || '') === filterType) {
            b.className = b.className.replace('btn-ghost', 'btn-secondary');
        }
    });
}

// Auto-init if already on activity page (SSR render case)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ if(document.getElementById('activityFeed')) RT.init(); });
} else {
    if (document.getElementById('activityFeed')) RT.init();
}
// Also always start stats polling globally (for dashboard counters)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ if(!RT.statsTimer) RT.startStatsPolling(); });
} else {
    if (!RT.statsTimer) RT.startStatsPolling();
}
</script>

</body>
</html>
