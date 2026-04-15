<?php
session_start();
include "db.php";

$message = "";
$msg_type = "error"; // "error" or "success"

/* -------------------- REGISTER -------------------- */
if(isset($_POST['register'])){
    $name    = trim($_POST['fullname']);
    $email   = trim($_POST['email']);
    $pass    = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    $role    = $_POST['role'] ?? 'user';

    // Sanitize role — only allow valid values
    if(!in_array($role, ['user', 'admin'])) $role = 'user';

    if(empty($name) || empty($email) || empty($pass)){
        $message = "Please fill in all fields.";
    } elseif($pass != $confirm){
        $message = "Passwords do not match.";
    } elseif(strlen($pass) < 6){
        $message = "Password must be at least 6 characters.";
    } else {
        $check = $conn->prepare("SELECT id FROM people WHERE email=?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if($check->num_rows > 0){
            $message = "Email already exists.";
        } else {
            $password = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO people(fullname,email,password,role) VALUES(?,?,?,?)");
            $stmt->bind_param("ssss", $name, $email, $password, $role);

            if($stmt->execute()){
                $message  = "Registered successfully! You can now log in.";
                $msg_type = "success";
            } else {
                $message = "Error registering. Please try again.";
            }
        }
    }
}

/* -------------------- LOGIN -------------------- */
if(isset($_POST['login'])){
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if(empty($email) || empty($password)){
        $message = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM people WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows > 0){
            $user = $result->fetch_assoc();
            if(password_verify($password, $user['password'])){
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['LAST_ACTIVITY'] = time();

                if(isset($_POST['remember'])){
                    setcookie('user_id', $user['id'],  time() + (86400*30), "/");
                    setcookie('role',    $user['role'], time() + (86400*30), "/");
                }

                if($user['role'] === "admin"){
                    header("Location: admin_dashboard.php");
                } else {
                    header("Location: user_dashboard.php");
                }
                exit();
            } else {
                $message = "Invalid email or password.";
            }
        } else {
            $message = "Invalid email or password.";
        }
    }
}

/* -------------------- AUTO LOGIN VIA COOKIE -------------------- */
if(!isset($_SESSION['user_id']) && isset($_COOKIE['user_id']) && isset($_COOKIE['role'])){
    $_SESSION['user_id']       = $_COOKIE['user_id'];
    $_SESSION['role']          = $_COOKIE['role'];
    $_SESSION['LAST_ACTIVITY'] = time();

    header("Location: " . ($_SESSION['role'] === "admin" ? "admin_dashboard.php" : "user_dashboard.php"));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAOPS — Login</title>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@400;600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

<style>
/* ===== BASE ===== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body, html {
    height: 100%;
    font-family: 'Rajdhani', sans-serif;
    background: #050d1a;
    color: white;
    overflow: hidden;
}

/* ===== PARTICLE CANVAS ===== */
#particles-js {
    position: fixed;
    width: 100%; height: 100%;
    top: 0; left: 0;
    z-index: 1;
}

/* ===== GRID OVERLAY ===== */
.grid-overlay {
    position: fixed;
    width: 100%; height: 100%;
    top: 0; left: 0;
    z-index: 2;
    background-image:
        linear-gradient(rgba(0,255,255,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,255,255,0.03) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
}

/* ===== GLOW ORBS ===== */
.orb {
    position: fixed;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.18;
    z-index: 2;
    pointer-events: none;
    animation: orbFloat 8s ease-in-out infinite alternate;
}
.orb-1 { width: 500px; height: 500px; background: #4facfe; top: -150px; left: -150px; animation-delay: 0s; }
.orb-2 { width: 400px; height: 400px; background: #00c6ff; bottom: -100px; right: -100px; animation-delay: 3s; }
.orb-3 { width: 300px; height: 300px; background: #0062ff; top: 40%; left: 40%; animation-delay: 1.5s; }

@keyframes orbFloat {
    from { transform: translate(0, 0) scale(1); }
    to   { transform: translate(30px, 20px) scale(1.08); }
}

/* ===== SCAN LINE ===== */
.scanline {
    position: fixed;
    width: 100%; height: 3px;
    background: linear-gradient(90deg, transparent, rgba(0,255,255,0.4), transparent);
    top: 0; left: 0;
    z-index: 3;
    animation: scan 6s linear infinite;
    pointer-events: none;
}
@keyframes scan {
    0%   { top: -3px; opacity: 1; }
    95%  { opacity: 0.6; }
    100% { top: 100vh; opacity: 0; }
}

/* ===== CENTER WRAPPER ===== */
.center {
    position: relative;
    z-index: 10;
    width: 100%; height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

/* ===== CARD ===== */
.card {
    width: 100%;
    max-width: 440px;
    background: rgba(5, 20, 40, 0.75);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(0, 255, 255, 0.18);
    border-radius: 20px;
    padding: 35px 35px 30px;
    box-shadow:
        0 0 0 1px rgba(0,255,255,0.05),
        0 25px 60px rgba(0,0,0,0.6),
        inset 0 1px 0 rgba(255,255,255,0.05);
    animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) both;
}

@keyframes cardIn {
    from { opacity: 0; transform: translateY(30px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0)   scale(1); }
}

/* ===== BRAND ===== */
.brand {
    text-align: center;
    margin-bottom: 22px;
}
.brand-icon {
    font-size: 2.4rem;
    color: #4facfe;
    margin-bottom: 6px;
    display: block;
    filter: drop-shadow(0 0 12px rgba(79,172,254,0.7));
    animation: pulse 3s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { filter: drop-shadow(0 0 10px rgba(79,172,254,0.6)); }
    50%       { filter: drop-shadow(0 0 22px rgba(79,172,254,1)); }
}
.brand h1 {
    font-family: 'Orbitron', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    letter-spacing: 4px;
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1;
}
.brand p {
    font-size: 0.72rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: rgba(0,255,255,0.45);
    margin-top: 5px;
}

/* ===== TAB NAV ===== */
.tabs {
    display: flex;
    gap: 0;
    margin-bottom: 22px;
    background: rgba(0,0,0,0.35);
    border-radius: 10px;
    padding: 4px;
    border: 1px solid rgba(0,255,255,0.1);
}
.tab-btn {
    flex: 1;
    padding: 9px 0;
    border: none;
    background: transparent;
    color: rgba(255,255,255,0.45);
    font-family: 'Rajdhani', sans-serif;
    font-size: 0.88rem;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    border-radius: 7px;
    cursor: pointer;
    transition: all 0.25s;
}
.tab-btn.active {
    background: linear-gradient(135deg, rgba(79,172,254,0.25), rgba(0,198,255,0.15));
    color: #00ffff;
    box-shadow: 0 0 12px rgba(0,255,255,0.12);
}

/* ===== FORM ===== */
.form-section { display: none; }
.form-section.visible { display: block; animation: fadeUp 0.3s ease both; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.form-title {
    font-family: 'Orbitron', sans-serif;
    font-size: 0.9rem;
    letter-spacing: 2px;
    color: rgba(0,255,255,0.6);
    text-transform: uppercase;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(0,255,255,0.1);
}

/* ===== INPUT GROUP ===== */
.input-group {
    position: relative;
    margin-bottom: 12px;
}
.input-group i.icon {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(0,255,255,0.45);
    font-size: 0.85rem;
    pointer-events: none;
    z-index: 1;
}
.input-group input,
.input-group select {
    width: 100%;
    padding: 11px 14px 11px 38px;
    background: rgba(0,0,0,0.4);
    border: 1px solid rgba(0,255,255,0.2);
    border-radius: 9px;
    color: rgba(255,255,255,0.9);
    font-family: 'Rajdhani', sans-serif;
    font-size: 0.95rem;
    outline: none;
    transition: border 0.2s, box-shadow 0.2s;
    appearance: none;
    -webkit-appearance: none;
}
.input-group input::placeholder { color: rgba(255,255,255,0.3); }
.input-group input:focus,
.input-group select:focus {
    border-color: #4facfe;
    box-shadow: 0 0 0 3px rgba(79,172,254,0.12);
}
.input-group select option { background: #0d1f35; color: white; }

/* Eye toggle */
.eye-btn {
    position: absolute;
    right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: rgba(0,255,255,0.4);
    cursor: pointer;
    font-size: 0.85rem;
    transition: color 0.2s;
    z-index: 2;
    padding: 0;
}
.eye-btn:hover { color: #00ffff; }

/* ===== REMEMBER ME ===== */
.remember {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 8px 0 14px;
    font-size: 0.82rem;
    color: rgba(255,255,255,0.5);
    cursor: pointer;
}
.remember input[type="checkbox"] {
    accent-color: #4facfe;
    width: 14px; height: 14px;
}

/* ===== ROLE SELECTOR ===== */
.role-selector {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
}
.role-option {
    flex: 1;
    padding: 10px 8px;
    border: 1px solid rgba(0,255,255,0.18);
    border-radius: 9px;
    background: rgba(0,0,0,0.3);
    color: rgba(255,255,255,0.5);
    font-family: 'Rajdhani', sans-serif;
    font-size: 0.82rem;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
}
.role-option i { font-size: 1.2rem; }
.role-option:hover {
    border-color: rgba(0,255,255,0.4);
    color: rgba(255,255,255,0.8);
}
.role-option.selected {
    border-color: #4facfe;
    background: rgba(79,172,254,0.15);
    color: #00ffff;
    box-shadow: 0 0 12px rgba(79,172,254,0.15);
}
.role-option input[type="radio"] { display: none; }
.role-label {
    font-size: 0.7rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 6px;
    color: rgba(0,255,255,0.5);
}

/* ===== SUBMIT BTN ===== */
.submit-btn {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #4facfe, #00c6ff);
    color: #050d1a;
    font-family: 'Orbitron', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    cursor: pointer;
    margin-top: 4px;
    transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
    box-shadow: 0 4px 20px rgba(79,172,254,0.3);
}
.submit-btn:hover {
    opacity: 0.92;
    transform: translateY(-1px);
    box-shadow: 0 6px 25px rgba(79,172,254,0.45);
}
.submit-btn:active { transform: translateY(0); }

/* ===== MESSAGE ===== */
.message {
    padding: 9px 14px;
    border-radius: 8px;
    font-size: 0.85rem;
    margin-bottom: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.message.error   { background: rgba(255,71,87,0.15);  border: 1px solid rgba(255,71,87,0.4);  color: #ff6b81; }
.message.success { background: rgba(46,204,113,0.15); border: 1px solid rgba(46,204,113,0.4); color: #2ecc71; }

/* ===== SWITCH LINK ===== */
.switch-link {
    text-align: center;
    margin-top: 14px;
    font-size: 0.82rem;
    color: rgba(255,255,255,0.35);
}
.switch-link span {
    color: #4facfe;
    cursor: pointer;
    transition: color 0.2s;
}
.switch-link span:hover { color: #00ffff; }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .card { padding: 25px 20px 22px; }
    .brand h1 { font-size: 1.6rem; }
}
</style>
</head>
<body>

<!-- Backgrounds -->
<div id="particles-js"></div>
<div class="grid-overlay"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
<div class="scanline"></div>

<div class="center">
    <div class="card">

        <!-- Brand -->
        <div class="brand">
            <i class="fas fa-microchip brand-icon"></i>
            <h1>PAOPS</h1>
            <p>Performance & Academic Operations</p>
        </div>

        <!-- Message -->
        <?php if($message): ?>
        <div class="message <?php echo $msg_type; ?>">
            <i class="fas <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" id="btnLogin" onclick="showLogin()">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
            <button class="tab-btn" id="btnRegister" onclick="showRegister()">
                <i class="fas fa-user-plus"></i> Register
            </button>
        </div>

        <!-- ===== LOGIN FORM ===== -->
        <div class="form-section visible" id="login">
            <div class="form-title"><i class="fas fa-lock"></i> &nbsp;Secure Login</div>
            <form method="POST">
                <div class="input-group">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" name="email" placeholder="Email address" required>
                </div>
                <div class="input-group">
                    <i class="fas fa-key icon"></i>
                    <input type="password" id="loginPass" name="password" placeholder="Password" required>
                    <button type="button" class="eye-btn" onclick="togglePass('loginPass', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <label class="remember">
                    <input type="checkbox" name="remember"> Remember me for 30 days
                </label>
                <button type="submit" name="login" class="submit-btn">
                    <i class="fas fa-arrow-right-to-bracket"></i> &nbsp;Login
                </button>
            </form>
            <div class="switch-link">Don't have an account? <span onclick="showRegister()">Register here</span></div>
        </div>

        <!-- ===== REGISTER FORM ===== -->
        <div class="form-section" id="register">
            <div class="form-title"><i class="fas fa-user-plus"></i> &nbsp;Create Account</div>
            <form method="POST">
                <div class="input-group">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="fullname" placeholder="Full name" required>
                </div>
                <div class="input-group">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" name="email" placeholder="Email address" required>
                </div>
                <div class="input-group">
                    <i class="fas fa-key icon"></i>
                    <input type="password" id="regPass1" name="password" placeholder="Create password" required>
                    <button type="button" class="eye-btn" onclick="togglePass('regPass1', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" id="regPass2" name="confirm_password" placeholder="Confirm password" required>
                    <button type="button" class="eye-btn" onclick="togglePass('regPass2', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <!-- ROLE SELECTOR -->
                <div class="role-label">Select your role</div>
                <div class="role-selector">
                    <label class="role-option selected" id="roleUser" onclick="selectRole('user')">
                        <input type="radio" name="role" value="user" checked>
                        <i class="fas fa-user-graduate"></i>
                        Student
                    </label>
                    <label class="role-option" id="roleAdmin" onclick="selectRole('admin')">
                        <input type="radio" name="role" value="admin">
                        <i class="fas fa-user-shield"></i>
                        Admin
                    </label>
                </div>

                <button type="submit" name="register" class="submit-btn">
                    <i class="fas fa-rocket"></i> &nbsp;Create Account
                </button>
            </form>
            <div class="switch-link">Already have an account? <span onclick="showLogin()">Login here</span></div>
        </div>

    </div>
</div>

<!-- Particles.js -->
<script src="https://cdn.jsdelivr.net/npm/particles.js"></script>
<script>
particlesJS('particles-js', {
    particles: {
        number: { value: 120, density: { enable: true, value_area: 900 } },
        color: { value: "#4facfe" },
        shape: { type: "circle" },
        opacity: { value: 0.45, random: true, anim: { enable: true, speed: 0.8, opacity_min: 0.1 } },
        size: { value: 2.5, random: true },
        line_linked: { enable: true, distance: 130, color: "#00c6ff", opacity: 0.25, width: 1 },
        move: { enable: true, speed: 1.5, direction: "none", random: true, straight: false, out_mode: "out" }
    },
    interactivity: {
        detect_on: "canvas",
        events: {
            onhover: { enable: true, mode: "grab" },
            onclick: { enable: true, mode: "push" }
        },
        modes: {
            grab: { distance: 160, line_linked: { opacity: 0.6 } },
            push: { particles_nb: 4 }
        }
    },
    retina_detect: true
});

/* ===== TAB SWITCHING ===== */
function showLogin() {
    document.getElementById('login').classList.add('visible');
    document.getElementById('register').classList.remove('visible');
    document.getElementById('btnLogin').classList.add('active');
    document.getElementById('btnRegister').classList.remove('active');
}
function showRegister() {
    document.getElementById('register').classList.add('visible');
    document.getElementById('login').classList.remove('visible');
    document.getElementById('btnRegister').classList.add('active');
    document.getElementById('btnLogin').classList.remove('active');
}

/* ===== ROLE SELECT ===== */
function selectRole(role) {
    document.getElementById('roleUser').classList.toggle('selected', role === 'user');
    document.getElementById('roleAdmin').classList.toggle('selected', role === 'admin');
    document.querySelector(`input[name="role"][value="${role}"]`).checked = true;
}

/* ===== PASSWORD TOGGLE ===== */
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
        btn.style.color = '#00ffff';
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
        btn.style.color = '';
    }
}

/* ===== AUTO SHOW REGISTER IF MESSAGE IS SUCCESS ===== */
<?php if($msg_type === 'success'): ?>
window.onload = () => showLogin();
<?php elseif(isset($_POST['register'])): ?>
window.onload = () => showRegister();
<?php else: ?>
window.onload = () => showLogin();
<?php endif; ?>
</script>
</body>
</html>