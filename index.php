<?php
session_start();
include "db.php";

/* -------------------- REGISTER (AJAX only) -------------------- */
if(isset($_POST['register']) && !empty($_POST['ajax'])){
    header('Content-Type: application/json');

    $name    = trim($_POST['fullname'] ?? '');
    $email   = trim($_POST['email']   ?? '');
    $pass    = $_POST['password']         ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $role    = $_POST['role']             ?? 'user';

    if(!in_array($role, ['user','admin'])) $role = 'user';

    if(empty($name) || empty($email) || empty($pass)){
        echo json_encode(['ok'=>false,'msg'=>'Please fill in all fields.']); exit();
    }
    if($pass !== $confirm){
        echo json_encode(['ok'=>false,'msg'=>'Passwords do not match.']); exit();
    }
    if(strlen($pass) < 6){
        echo json_encode(['ok'=>false,'msg'=>'Password must be at least 6 characters.']); exit();
    }
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        echo json_encode(['ok'=>false,'msg'=>'Invalid email address.']); exit();
    }

    $check = $conn->prepare("SELECT id FROM people WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if($check->num_rows > 0){
        echo json_encode(['ok'=>false,'msg'=>'That email is already registered.']); exit();
    }

    $hashed = password_hash($pass, PASSWORD_DEFAULT);
    $stmt   = $conn->prepare("INSERT INTO people(fullname,email,password,role) VALUES(?,?,?,?)");
    $stmt->bind_param("ssss", $name, $email, $hashed, $role);

    if($stmt->execute()){
        echo json_encode(['ok'=>true,'msg'=>'Account created! You can now log in.']); exit();
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Error registering. Please try again.']); exit();
    }
}

/* -------------------- LOGIN LOCKOUT CONSTANTS -------------------- */
define('MAX_ATTEMPTS', 3);
define('LOCKOUT_SECS', 60);

/* -------------------- LOGIN -------------------- */
$login_message  = '';
$login_msg_type = 'error';

if(!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if(!isset($_SESSION['lockout_until']))  $_SESSION['lockout_until']  = 0;

$lockout_remaining = ($_SESSION['lockout_until'] > time()) ? $_SESSION['lockout_until'] - time() : 0;
$attempts_left     = max(0, MAX_ATTEMPTS - $_SESSION['login_attempts']);

if(isset($_POST['login'])){
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';

    if($_SESSION['lockout_until'] > time()){
        $lockout_remaining = $_SESSION['lockout_until'] - time();
        $login_message     = "Too many failed attempts. Please wait {$lockout_remaining} second(s).";
    } elseif(empty($email) || empty($password)){
        $login_message = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM people WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        $login_ok = false;
        if($result->num_rows > 0){
            $user = $result->fetch_assoc();
            if(password_verify($password, $user['password'])){
                $login_ok = true;
            }
        }

        if($login_ok){
            $_SESSION['login_attempts'] = 0;
            $_SESSION['lockout_until']  = 0;
            $_SESSION['user_id']        = $user['id'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['LAST_ACTIVITY']  = time();

            if(isset($_POST['remember'])){
                setcookie('user_id', $user['id'],  time() + (86400*30), "/");
                setcookie('role',    $user['role'], time() + (86400*30), "/");
            }

            header("Location: " . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php'));
            exit();
        } else {
            $_SESSION['login_attempts']++;
            if($_SESSION['login_attempts'] >= MAX_ATTEMPTS){
                $_SESSION['lockout_until']  = time() + LOCKOUT_SECS;
                $_SESSION['login_attempts'] = 0;
                $lockout_remaining          = LOCKOUT_SECS;
                $attempts_left              = 0;
                $login_message              = "Too many failed attempts. Please wait " . LOCKOUT_SECS . " second(s).";
            } else {
                $attempts_left = MAX_ATTEMPTS - $_SESSION['login_attempts'];
                $login_message = "Invalid email or password. {$attempts_left} attempt(s) remaining.";
            }
        }
    }
}

/* -------------------- AUTO LOGIN VIA COOKIE -------------------- */
if(!isset($_SESSION['user_id']) && isset($_COOKIE['user_id']) && isset($_COOKIE['role'])){
    $_SESSION['user_id']       = $_COOKIE['user_id'];
    $_SESSION['role']          = $_COOKIE['role'];
    $_SESSION['LAST_ACTIVITY'] = time();
    header("Location: " . ($_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php'));
    exit();
}

/* Open on register tab if we just came from a failed login POST — keep login default otherwise */
$open_register = false;
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body, html {
    height: 100%;
    font-family: 'Rajdhani', sans-serif;
    background: #050d1a;
    color: white;
    overflow: hidden;
}
#particles-js { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: 1; }
.grid-overlay {
    position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: 2;
    background-image:
        linear-gradient(rgba(0,255,255,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,255,255,0.03) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
}
.orb { position: fixed; border-radius: 50%; filter: blur(80px); opacity: 0.18; z-index: 2; pointer-events: none; animation: orbFloat 8s ease-in-out infinite alternate; }
.orb-1 { width: 500px; height: 500px; background: #4facfe; top: -150px; left: -150px; animation-delay: 0s; }
.orb-2 { width: 400px; height: 400px; background: #00c6ff; bottom: -100px; right: -100px; animation-delay: 3s; }
.orb-3 { width: 300px; height: 300px; background: #0062ff; top: 40%; left: 40%; animation-delay: 1.5s; }
@keyframes orbFloat { from { transform: translate(0,0) scale(1); } to { transform: translate(30px,20px) scale(1.08); } }
.scanline { position: fixed; width: 100%; height: 3px; background: linear-gradient(90deg,transparent,rgba(0,255,255,0.4),transparent); top: 0; left: 0; z-index: 3; animation: scan 6s linear infinite; pointer-events: none; }
@keyframes scan { 0% { top: -3px; opacity: 1; } 95% { opacity: 0.6; } 100% { top: 100vh; opacity: 0; } }
.center { position: relative; z-index: 10; width: 100%; height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
.card {
    width: 100%; max-width: 440px;
    background: rgba(5,20,40,0.75);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(0,255,255,0.18);
    border-radius: 20px;
    padding: 35px 35px 30px;
    box-shadow: 0 0 0 1px rgba(0,255,255,0.05), 0 25px 60px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.05);
    animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) both;
}
@keyframes cardIn { from { opacity: 0; transform: translateY(30px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
.brand { text-align: center; margin-bottom: 22px; }
.brand-icon { font-size: 2.4rem; color: #4facfe; margin-bottom: 6px; display: block; filter: drop-shadow(0 0 12px rgba(79,172,254,0.7)); animation: pulse 3s ease-in-out infinite; }
@keyframes pulse { 0%,100% { filter: drop-shadow(0 0 10px rgba(79,172,254,0.6)); } 50% { filter: drop-shadow(0 0 22px rgba(79,172,254,1)); } }
.brand h1 { font-family: 'Orbitron',sans-serif; font-size: 2rem; font-weight: 900; letter-spacing: 4px; background: linear-gradient(135deg,#4facfe,#00f2fe); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }
.brand p { font-size: 0.72rem; letter-spacing: 3px; text-transform: uppercase; color: rgba(0,255,255,0.45); margin-top: 5px; }
.tabs { display: flex; margin-bottom: 22px; background: rgba(0,0,0,0.35); border-radius: 10px; padding: 4px; border: 1px solid rgba(0,255,255,0.1); }
.tab-btn { flex: 1; padding: 9px 0; border: none; background: transparent; color: rgba(255,255,255,0.45); font-family: 'Rajdhani',sans-serif; font-size: 0.88rem; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; border-radius: 7px; cursor: pointer; transition: all 0.25s; }
.tab-btn.active { background: linear-gradient(135deg,rgba(79,172,254,0.25),rgba(0,198,255,0.15)); color: #00ffff; box-shadow: 0 0 12px rgba(0,255,255,0.12); }
.form-section { display: none; }
.form-section.visible { display: block; animation: fadeUp 0.3s ease both; }
@keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.form-title { font-family: 'Orbitron',sans-serif; font-size: 0.9rem; letter-spacing: 2px; color: rgba(0,255,255,0.6); text-transform: uppercase; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid rgba(0,255,255,0.1); }
.input-group { position: relative; margin-bottom: 12px; }
.input-group i.icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: rgba(0,255,255,0.45); font-size: 0.85rem; pointer-events: none; z-index: 1; }
.input-group input { width: 100%; padding: 11px 14px 11px 38px; background: rgba(0,0,0,0.4); border: 1px solid rgba(0,255,255,0.2); border-radius: 9px; color: rgba(255,255,255,0.9); font-family: 'Rajdhani',sans-serif; font-size: 0.95rem; outline: none; transition: border 0.2s, box-shadow 0.2s; }
.input-group input::placeholder { color: rgba(255,255,255,0.3); }
.input-group input:focus { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,0.12); }
/* Error shake animation */
.input-group input.shake { animation: shake 0.35s ease; border-color: #ff6b81 !important; }
@keyframes shake { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-6px)} 40%{transform:translateX(6px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(4px)} }
.eye-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: rgba(0,255,255,0.4); cursor: pointer; font-size: 0.85rem; transition: color 0.2s; z-index: 2; padding: 0; }
.eye-btn:hover { color: #00ffff; }
.remember { display: flex; align-items: center; gap: 8px; margin: 8px 0 14px; font-size: 0.82rem; color: rgba(255,255,255,0.5); cursor: pointer; }
.remember input[type="checkbox"] { accent-color: #4facfe; width: 14px; height: 14px; }
.role-label { font-size: 0.7rem; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 6px; color: rgba(0,255,255,0.5); }
.role-selector { display: flex; gap: 8px; margin-bottom: 12px; }
.role-option { flex: 1; padding: 10px 8px; border: 1px solid rgba(0,255,255,0.18); border-radius: 9px; background: rgba(0,0,0,0.3); color: rgba(255,255,255,0.5); font-family: 'Rajdhani',sans-serif; font-size: 0.82rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; text-align: center; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; gap: 5px; }
.role-option i { font-size: 1.2rem; }
.role-option:hover { border-color: rgba(0,255,255,0.4); color: rgba(255,255,255,0.8); }
.role-option.selected { border-color: #4facfe; background: rgba(79,172,254,0.15); color: #00ffff; box-shadow: 0 0 12px rgba(79,172,254,0.15); }
.role-option input[type="radio"] { display: none; }
.submit-btn { width: 100%; padding: 12px; border: none; border-radius: 10px; background: linear-gradient(135deg,#4facfe,#00c6ff); color: #050d1a; font-family: 'Orbitron',sans-serif; font-size: 0.82rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; cursor: pointer; margin-top: 4px; transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s; box-shadow: 0 4px 20px rgba(79,172,254,0.3); }
.submit-btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 6px 25px rgba(79,172,254,0.45); }
.submit-btn:active { transform: translateY(0); }
.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.message { padding: 9px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.message.error   { background: rgba(255,71,87,0.15);  border: 1px solid rgba(255,71,87,0.4);  color: #ff6b81; }
.message.success { background: rgba(46,204,113,0.15); border: 1px solid rgba(46,204,113,0.4); color: #2ecc71; }
/* Inline register messages (shown via JS, not PHP) */
#regMsg { display: none; margin-bottom: 12px; }
.switch-link { text-align: center; margin-top: 14px; font-size: 0.82rem; color: rgba(255,255,255,0.35); }
.switch-link span { color: #4facfe; cursor: pointer; transition: color 0.2s; }
.switch-link span:hover { color: #00ffff; }
@media (max-width: 480px) { .card { padding: 25px 20px 22px; } .brand h1 { font-size: 1.6rem; } }
</style>
</head>
<body>

<div id="particles-js"></div>
<div class="grid-overlay"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
<div class="scanline"></div>

<div class="center">
  <div class="card">

    <div class="brand">
      <i class="fas fa-microchip brand-icon"></i>
      <h1>PAOPS</h1>
      <p>Performance &amp; Academic Operations</p>
    </div>

    <!-- Login-only server message (failed login POST) -->
    <?php if($login_message): ?>
    <div class="message error" id="loginMsg">
      <i class="fas fa-exclamation-circle"></i>
      <?php echo htmlspecialchars($login_message); ?>
    </div>
    <?php endif; ?>

    <div class="tabs">
      <button class="tab-btn active" id="btnLogin"    onclick="showTab('login')"><i class="fas fa-sign-in-alt"></i> Login</button>
      <button class="tab-btn"        id="btnRegister" onclick="showTab('register')"><i class="fas fa-user-plus"></i> Register</button>
    </div>

    <!-- ===== LOGIN FORM ===== -->
    <div class="form-section visible" id="tabLogin">
      <div class="form-title"><i class="fas fa-lock"></i> &nbsp;Secure Login</div>
      <form method="POST" autocomplete="on" id="loginForm">
        <div class="input-group">
          <i class="fas fa-envelope icon"></i>
          <input type="email" name="email" id="loginEmail" placeholder="Email address"
                 autocomplete="username" required>
        </div>
        <div class="input-group">
          <i class="fas fa-key icon"></i>
          <input type="password" id="loginPass" name="password" placeholder="Password"
                 autocomplete="current-password" required>
          <button type="button" class="eye-btn" onclick="togglePass('loginPass',this)">
            <i class="fas fa-eye"></i>
          </button>
        </div>
        <!-- Attempt counter badge — hidden when full attempts remain -->
        <div id="attemptBar" style="margin:-4px 0 10px;font-size:.78rem;color:rgba(255,200,100,.85);display:none;">
          <i class="fas fa-exclamation-triangle"></i> <span id="attemptTxt"></span>
        </div>
        <button type="submit" name="login" id="loginBtn" class="submit-btn">
          <i class="fas fa-arrow-right-to-bracket"></i> &nbsp;Login
        </button>
      </form>
      <div class="switch-link">Don't have an account? <span onclick="showTab('register')">Register here</span></div>
    </div>

    <!-- ===== REGISTER FORM ===== -->
    <div class="form-section" id="tabRegister">
      <div class="form-title"><i class="fas fa-user-plus"></i> &nbsp;Create Account</div>

      <!-- JS-driven message box — never shown during a real form submit -->
      <div class="message" id="regMsg"></div>

      <!--
        autocomplete="off" on the whole form + new-password on password fields:
        • Prevents the browser from offering to save credentials on failure
        • Keeps register credentials separate from login autofill
      -->
      <form id="regForm" autocomplete="off" onsubmit="submitRegister(event)">
        <div class="input-group">
          <i class="fas fa-user icon"></i>
          <input type="text" id="regName" name="fullname" placeholder="Full name"
                 autocomplete="off" required>
        </div>
        <div class="input-group">
          <i class="fas fa-envelope icon"></i>
          <input type="email" id="regEmail" name="email" placeholder="Email address"
                 autocomplete="off" required>
        </div>
        <div class="input-group">
          <i class="fas fa-key icon"></i>
          <input type="password" id="regPass1" name="password" placeholder="Create password"
                 autocomplete="new-password" required>
          <button type="button" class="eye-btn" onclick="togglePass('regPass1',this)">
            <i class="fas fa-eye"></i>
          </button>
        </div>
        <div class="input-group">
          <i class="fas fa-lock icon"></i>
          <input type="password" id="regPass2" name="confirm_password" placeholder="Confirm password"
                 autocomplete="new-password" required>
          <button type="button" class="eye-btn" onclick="togglePass('regPass2',this)">
            <i class="fas fa-eye"></i>
          </button>
        </div>

        <div class="role-label">Select your role</div>
        <div class="role-selector">
          <label class="role-option selected" id="roleUser" onclick="selectRole('user')">
            <input type="radio" name="role" value="user" checked>
            <i class="fas fa-user-graduate"></i> Student
          </label>
          <label class="role-option" id="roleAdmin" onclick="selectRole('admin')">
            <input type="radio" name="role" value="admin">
            <i class="fas fa-user-shield"></i> Admin
          </label>
        </div>

        <button type="submit" id="regBtn" class="submit-btn">
          <i class="fas fa-rocket"></i> &nbsp;Create Account
        </button>
      </form>
      <div class="switch-link">Already have an account? <span onclick="showTab('login')">Login here</span></div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/particles.js"></script>
<script>
particlesJS('particles-js',{particles:{number:{value:120,density:{enable:true,value_area:900}},color:{value:"#4facfe"},shape:{type:"circle"},opacity:{value:0.45,random:true,anim:{enable:true,speed:0.8,opacity_min:0.1}},size:{value:2.5,random:true},line_linked:{enable:true,distance:130,color:"#00c6ff",opacity:0.25,width:1},move:{enable:true,speed:1.5,direction:"none",random:true,straight:false,out_mode:"out"}},interactivity:{detect_on:"canvas",events:{onhover:{enable:true,mode:"grab"},onclick:{enable:true,mode:"push"}},modes:{grab:{distance:160,line_linked:{opacity:0.6}},push:{particles_nb:4}}},retina_detect:true});

/* ── Tab switching ── */
function showTab(tab){
    const isLogin = tab === 'login';
    document.getElementById('tabLogin').classList.toggle('visible', isLogin);
    document.getElementById('tabRegister').classList.toggle('visible', !isLogin);
    document.getElementById('btnLogin').classList.toggle('active', isLogin);
    document.getElementById('btnRegister').classList.toggle('active', !isLogin);
    // Clear register message & fields when switching away
    if(isLogin) clearRegForm();
}

/* ── Role selector ── */
function selectRole(role){
    document.getElementById('roleUser').classList.toggle('selected', role==='user');
    document.getElementById('roleAdmin').classList.toggle('selected', role==='admin');
    document.querySelector(`input[name="role"][value="${role}"]`).checked = true;
}

/* ── Password toggle ── */
function togglePass(id, btn){
    const inp  = document.getElementById(id);
    const icon = btn.querySelector('i');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye',      !show);
    icon.classList.toggle('fa-eye-slash', show);
    btn.style.color = show ? '#00ffff' : '';
}

/* ── Show inline register message ── */
function showRegMsg(isError, text){
    const el = document.getElementById('regMsg');
    el.className = 'message ' + (isError ? 'error' : 'success');
    el.innerHTML = `<i class="fas ${isError?'fa-exclamation-circle':'fa-check-circle'}"></i> ${text}`;
    el.style.display = 'flex';
}

/* ── Shake a field on error ── */
function shakeField(id){
    const el = document.getElementById(id);
    if(!el) return;
    el.classList.remove('shake');
    void el.offsetWidth; // reflow to restart animation
    el.classList.add('shake');
    setTimeout(()=>el.classList.remove('shake'), 400);
}

/* ── Clear register form ── */
function clearRegForm(){
    document.getElementById('regForm').reset();
    selectRole('user');
    document.getElementById('regMsg').style.display = 'none';
}

/* ── AJAX register — browser NEVER sees a credential form submit on failure ── */
async function submitRegister(e){
    e.preventDefault(); // stop normal submit entirely

    const name    = document.getElementById('regName').value.trim();
    const email   = document.getElementById('regEmail').value.trim();
    const pass1   = document.getElementById('regPass1').value;
    const pass2   = document.getElementById('regPass2').value;
    const role    = document.querySelector('input[name="role"]:checked').value;
    const btn     = document.getElementById('regBtn');

    // ── Client-side validation first (no server round-trip needed) ──
    if(!name || !email || !pass1 || !pass2){
        showRegMsg(true, 'Please fill in all fields.');
        return;
    }
    if(pass1 !== pass2){
        showRegMsg(true, 'Passwords do not match.');
        shakeField('regPass1'); shakeField('regPass2');
        document.getElementById('regPass1').value = '';
        document.getElementById('regPass2').value = '';
        return;
    }
    if(pass1.length < 6){
        showRegMsg(true, 'Password must be at least 6 characters.');
        shakeField('regPass1');
        document.getElementById('regPass1').value = '';
        document.getElementById('regPass2').value = '';
        return;
    }

    // ── Disable button while request is in-flight ──
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> &nbsp;Creating…';

    const data = new FormData();
    data.append('register', '1');
    data.append('ajax',     '1');   // tells PHP to respond with JSON
    data.append('fullname', name);
    data.append('email',    email);
    data.append('password', pass1);
    data.append('confirm_password', pass2);
    data.append('role',     role);

    try {
        const res  = await fetch('index.php', { method:'POST', body:data });
        const json = await res.json();

        if(json.ok){
            // ── Success: clear everything, show message, switch to login ──
            clearRegForm();
            showRegMsg(false, json.msg);
            // After 2 s switch to login tab automatically
            setTimeout(()=>{ showTab('login'); }, 2000);
        } else {
            // ── Failure: show error, clear password fields only, NO browser save prompt ──
            showRegMsg(true, json.msg);

            // If the error is about a duplicate email, shake & clear email too
            if(json.msg.toLowerCase().includes('email')){
                shakeField('regEmail');
                document.getElementById('regEmail').value = '';
            }
            // Always clear passwords on any error
            shakeField('regPass1');
            document.getElementById('regPass1').value = '';
            document.getElementById('regPass2').value = '';
        }
    } catch(err){
        showRegMsg(true, 'Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rocket"></i> &nbsp;Create Account';
    }
}

/* ── On page load: open register tab only if it's a GET with ?tab=register ── */
window.addEventListener('DOMContentLoaded', ()=>{
    if(new URLSearchParams(location.search).get('tab') === 'register') showTab('register');
    initLockout();
});

/* ── Lockout + attempt counter ── */
// PHP passes current lockout state to JS
const LOCKOUT_SECS   = <?php echo LOCKOUT_SECS; ?>;
let   lockoutSeconds = <?php echo (int)$lockout_remaining; ?>;
let   attemptsLeft   = <?php echo (int)$attempts_left; ?>;
const MAX_ATTEMPTS   = <?php echo MAX_ATTEMPTS; ?>;

let lockoutTimer = null;

function initLockout(){
    if(lockoutSeconds > 0){
        applyLockout(lockoutSeconds);
    } else if(attemptsLeft > 0 && attemptsLeft < MAX_ATTEMPTS){
        // Show attempt counter without locking
        showAttemptBar(attemptsLeft);
    }
}

function applyLockout(secs){
    const btn      = document.getElementById('loginBtn');
    const emailInp = document.getElementById('loginEmail');
    const passInp  = document.getElementById('loginPass');
    const bar      = document.getElementById('attemptBar');
    const txt      = document.getElementById('attemptTxt');

    // Disable all login inputs
    btn.disabled      = true;
    emailInp.disabled = true;
    passInp.disabled  = true;

    // Shake the password field to signal the lockout
    shakeField('loginPass');

    let remaining = secs;

    function tick(){
        btn.innerHTML = `<i class="fas fa-lock"></i> &nbsp;Locked — ${remaining}s`;
        bar.style.display = 'block';
        bar.style.color   = '#ff6b81';
        txt.textContent   = `Account locked. Try again in ${remaining} second${remaining!==1?'s':''}.`;

        if(remaining <= 0){
            clearInterval(lockoutTimer);
            // Re-enable inputs
            btn.disabled      = false;
            emailInp.disabled = false;
            passInp.disabled  = false;
            btn.innerHTML     = '<i class="fas fa-arrow-right-to-bracket"></i> &nbsp;Login';
            bar.style.display = 'none';
            attemptsLeft      = MAX_ATTEMPTS; // reset visual counter
        }
        remaining--;
    }

    tick(); // run immediately
    lockoutTimer = setInterval(tick, 1000);
}

function showAttemptBar(left){
    const bar = document.getElementById('attemptBar');
    const txt = document.getElementById('attemptTxt');
    if(!bar) return;
    bar.style.display = 'block';
    bar.style.color   = left === 1 ? '#ff6b81' : 'rgba(255,200,100,.85)';
    txt.textContent   = `${left} attempt${left!==1?'s':''} remaining before 60-second lockout.`;
}
</script>
</body>
</html>