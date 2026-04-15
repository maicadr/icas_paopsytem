<?php
session_start();
include "db.php";

/* ── AUTH ── */
if(isset($_GET['logout'])){
    session_unset(); session_destroy();
    setcookie('user_id','',time()-3600,"/");
    setcookie('role','',time()-3600,"/");
    header("Location: index.php"); exit();
}
$timeout=1800;
if(!isset($_SESSION['user_id'])){
    if(isset($_COOKIE['user_id'])&&isset($_COOKIE['role'])){
        $_SESSION['user_id']=$_COOKIE['user_id'];
        $_SESSION['role']=$_COOKIE['role'];
        $_SESSION['LAST_ACTIVITY']=time();
    } else { header("Location: index.php"); exit(); }
} else {
    if(isset($_SESSION['LAST_ACTIVITY'])&&(time()-$_SESSION['LAST_ACTIVITY']>$timeout)){
        session_unset(); session_destroy();
        setcookie('user_id','',time()-3600,"/");
        setcookie('role','',time()-3600,"/");
        header("Location: index.php?message=Session expired"); exit();
    }
    $_SESSION['LAST_ACTIVITY']=time();
}
if(!isset($_SESSION['role'])||$_SESSION['role']!='admin'){
    header("Location: index.php"); exit();
}
$teacher_id = (int)$_SESSION['user_id'];

/* ── FETCH TEACHER ── */
$s=$conn->prepare("SELECT fullname FROM people WHERE id=?");
$s->bind_param("i",$teacher_id); $s->execute();
$teacher=$s->get_result()->fetch_assoc();
if(!$teacher){ header("Location: index.php"); exit(); }

$flash=''; $flash_type='success';

/* ==================== POST HANDLERS ==================== */

/* CREATE CLASS */
if(isset($_POST['create_class'])){
    $cn = trim($_POST['class_name']);
    if($cn === ''){
        $flash="Class name cannot be empty."; $flash_type='error';
    } else {
        /* check duplicate for this teacher */
        $chk=$conn->prepare("SELECT id FROM classes WHERE teacher_id=? AND class_name=?");
        $chk->bind_param("is",$teacher_id,$cn); $chk->execute(); $chk->store_result();
        if($chk->num_rows>0){
            $flash="You already have a class named '$cn'."; $flash_type='error';
        } else {
            $s=$conn->prepare("INSERT INTO classes(teacher_id,class_name) VALUES(?,?)");
            $s->bind_param("is",$teacher_id,$cn); $s->execute();
            $flash="Class '$cn' created successfully!"; $flash_type='success';
        }
    }
}

/* RENAME CLASS */
if(isset($_POST['rename_class'])){
    $cid=(int)$_POST['class_id'];
    $cn=trim($_POST['new_name']);
    if($cn===''){
        $flash="Class name cannot be empty."; $flash_type='error';
    } else {
        /* only allow renaming own classes */
        $s=$conn->prepare("UPDATE classes SET class_name=? WHERE id=? AND teacher_id=?");
        $s->bind_param("sii",$cn,$cid,$teacher_id); $s->execute();
        if($s->affected_rows>0){ $flash="Class renamed to '$cn'."; $flash_type='success'; }
        else { $flash="Could not rename — class not found or not yours."; $flash_type='error'; }
    }
}

/* DELETE CLASS */
if(isset($_POST['delete_class'])){
    $cid=(int)$_POST['class_id'];
    /* only delete own classes */
    $s=$conn->prepare("DELETE FROM classes WHERE id=? AND teacher_id=?");
    $s->bind_param("ii",$cid,$teacher_id); $s->execute();
    if($s->affected_rows>0){ $flash="Class deleted."; $flash_type='success'; }
    else { $flash="Could not delete — class not found or not yours."; $flash_type='error'; }
}

/* ENROLL STUDENT */
if(isset($_POST['enroll_student'])){
    $sid=(int)$_POST['student_id'];
    $cid=(int)$_POST['class_id'];
    if($sid===0||$cid===0){ $flash="Please select a student."; $flash_type='error'; }
    else {
    /* verify class belongs to this teacher */
        $own=$conn->prepare("SELECT id FROM classes WHERE id=? AND teacher_id=?");
        $own->bind_param("ii",$cid,$teacher_id); $own->execute(); $own->store_result();
    }
        if($own->num_rows===0){
        $flash="You can only enroll students into your own classes."; $flash_type='error';
    } else {
        $chk=$conn->prepare("SELECT id FROM enrollments WHERE student_id=? AND class_id=?");
        $chk->bind_param("ii",$sid,$cid); $chk->execute(); $chk->store_result();
        if($chk->num_rows>0){ $flash="Student already enrolled in that class."; $flash_type='error'; }
        else {
            $s=$conn->prepare("INSERT INTO enrollments(student_id,class_id) VALUES(?,?)");
            $s->bind_param("ii",$sid,$cid); $s->execute();
            $flash="Student enrolled successfully."; $flash_type='success';
        }
    }
}

/* UNENROLL STUDENT */
if(isset($_POST['unenroll_student'])){
    $eid=(int)$_POST['enrollment_id'];
    /* verify enrollment is in one of this teacher's classes */
    $chk=$conn->prepare("SELECT e.id FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.id=? AND c.teacher_id=?");
    $chk->bind_param("ii",$eid,$teacher_id); $chk->execute(); $chk->store_result();
    if($chk->num_rows>0){
        $s=$conn->prepare("DELETE FROM enrollments WHERE id=?");
        $s->bind_param("i",$eid); $s->execute();
        $flash="Student removed from class."; $flash_type='success';
    } else {
        $flash="Cannot remove — enrollment not found or not yours."; $flash_type='error';
    }
}

/* ==================== FETCH DATA ==================== */

/* All classes owned by this teacher with enrollment count */
$my_classes=$conn->query("
    SELECT c.id, c.class_name,
           COUNT(e.id) AS student_count
    FROM classes c
    LEFT JOIN enrollments e ON e.class_id = c.id
    WHERE c.teacher_id = $teacher_id
    GROUP BY c.id
    ORDER BY c.class_name
");

/* All students (for enroll dropdown) */
$all_students=$conn->query("SELECT id, fullname, email FROM people WHERE role='user' ORDER BY fullname");

/* Enrollments per class (for student lists) */
$enrollments_by_class=[];
$eq=$conn->query("
    SELECT e.id AS eid, e.class_id, p.fullname, p.email
    FROM enrollments e
    JOIN people p ON p.id = e.student_id
    JOIN classes c ON c.id = e.class_id
    WHERE c.teacher_id = $teacher_id
    ORDER BY p.fullname
");
if($eq){ while($r=$eq->fetch_assoc()){ $enrollments_by_class[$r['class_id']][]=$r; } }

/* Stats */
$my_class_count = $conn->query("SELECT COUNT(*) c FROM classes WHERE teacher_id=$teacher_id")->fetch_assoc()['c'];
$my_enroll_count= $conn->query("
    SELECT COUNT(*) c FROM enrollments e
    JOIN classes c ON c.id = CAST(e.class_id AS UNSIGNED)
    WHERE c.teacher_id=$teacher_id
")->fetch_assoc()['c'];
$total_students = $conn->query("SELECT COUNT(*) c FROM people WHERE role='user'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Classes — PAOPS</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700&family=Rajdhani:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body,html{height:100%;font-family:'Rajdhani',sans-serif;background:#050d1a;color:#e0f7ff;overflow:hidden;}
#particles-js{position:fixed;width:100%;height:100%;top:0;left:0;z-index:1;}
.layout{display:flex;height:100vh;position:relative;z-index:10;}

/* SIDEBAR */
.sidebar{width:230px;min-width:230px;flex-shrink:0;background:rgba(2,8,22,0.97);display:flex;flex-direction:column;padding:18px 12px;height:100vh;overflow-y:auto;border-right:1px solid rgba(0,255,255,0.1);z-index:20;}
.sb-brand{text-align:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid rgba(0,255,255,0.1);}
.sb-brand i{font-size:1.8rem;color:#4facfe;filter:drop-shadow(0 0 8px rgba(79,172,254,0.7));}
.sb-brand h2{font-family:'Orbitron',sans-serif;font-size:1rem;color:#00ffff;letter-spacing:3px;margin:6px 0 2px;}
.sb-brand .sub{font-size:0.6rem;color:rgba(0,255,255,0.32);letter-spacing:2px;text-transform:uppercase;}
.sb-brand .uname{font-size:0.76rem;color:rgba(255,255,255,0.48);margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sl{font-size:0.58rem;letter-spacing:2px;text-transform:uppercase;color:rgba(0,255,255,0.2);margin:12px 0 3px 5px;}
.sidebar a{color:rgba(255,255,255,0.52);text-decoration:none;padding:9px 11px;display:flex;align-items:center;border-left:3px solid transparent;margin:2px 0;border-radius:8px;transition:0.2s;font-size:0.85rem;font-weight:600;white-space:nowrap;}
.sidebar a i{margin-right:9px;width:15px;text-align:center;font-size:0.82rem;}
.sidebar a:hover,.sidebar a.active{background:rgba(0,255,255,0.1);border-left:3px solid #4facfe;color:#00ffff;}
.sidebar a .bc{margin-left:auto;background:rgba(79,172,254,0.2);color:#4facfe;font-size:0.63rem;padding:1px 6px;border-radius:9px;}
.sb-foot{margin-top:auto;padding-top:12px;border-top:1px solid rgba(0,255,255,0.08);}
.sb-foot a{color:#ff6b81!important;}
.sb-foot a:hover{background:rgba(255,71,87,0.12)!important;border-left:3px solid #ff4757!important;color:#ff4757!important;}

/* MAIN */
.main{flex:1;overflow-y:auto;padding:20px 26px;min-width:0;z-index:20;}
.topbar{display:flex;justify-content:space-between;align-items:center;background:rgba(0,255,255,0.05);border:1px solid rgba(0,255,255,0.1);padding:10px 16px;border-radius:12px;margin-bottom:18px;gap:10px;}
.topbar .tw{font-weight:700;font-size:0.92rem;}
.topbar .tm{font-size:0.7rem;color:rgba(0,255,255,0.42);margin-top:2px;}
.topbar a{text-decoration:none;background:#ff4757;color:white;padding:7px 12px;border-radius:8px;font-size:0.8rem;flex-shrink:0;}
.topbar a:hover{background:#c0392b;}

/* FLASH */
.flash{padding:10px 15px;border-radius:10px;margin-bottom:16px;font-weight:600;font-size:0.88rem;display:flex;align-items:center;gap:8px;animation:fu 0.3s ease;}
.flash.success{background:rgba(46,204,113,0.12);border:1px solid rgba(46,204,113,0.3);color:#2ecc71;}
.flash.error{background:rgba(255,71,87,0.12);border:1px solid rgba(255,71,87,0.3);color:#ff6b81;}
@keyframes fu{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}

/* SEC TITLE */
.sec-title{font-family:'Orbitron',sans-serif;font-size:0.9rem;letter-spacing:2px;color:#00ffff;margin-bottom:16px;padding-bottom:9px;border-bottom:1px solid rgba(0,255,255,0.1);display:flex;align-items:center;gap:9px;}
.sec-title i{color:#4facfe;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.sc{background:rgba(0,255,255,0.04);border:1px solid rgba(0,255,255,0.11);border-radius:12px;padding:15px 12px;text-align:center;transition:transform 0.2s;}
.sc:hover{transform:translateY(-2px);}
.si{font-size:1.4rem;margin-bottom:5px;}
.sv{font-size:1.8rem;font-weight:700;line-height:1;}
.slb{font-size:0.62rem;text-transform:uppercase;letter-spacing:1px;color:rgba(0,255,255,0.42);margin-top:3px;}
.cb .si,.cb .sv{color:#4facfe;}
.cg .si,.cg .sv{color:#2ecc71;}
.ct .si,.ct .sv{color:#00cec9;}

/* CREATE CLASS CARD */
.create-card{background:rgba(0,255,255,0.04);border:1px solid rgba(0,255,255,0.12);border-radius:14px;padding:20px;margin-bottom:22px;}
.create-card h3{font-family:'Orbitron',sans-serif;font-size:0.82rem;letter-spacing:2px;color:#00ffff;margin-bottom:14px;}
.create-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.cf-group{display:flex;flex-direction:column;gap:4px;flex:1;min-width:200px;}
.cf-group label{font-size:0.68rem;text-transform:uppercase;letter-spacing:1px;color:rgba(0,255,255,0.4);}
.cf-group input,.cf-group select{padding:9px 12px;background:rgba(0,0,0,0.45);border:1px solid rgba(0,255,255,0.2);border-radius:9px;color:rgba(255,255,255,0.88);font-family:'Rajdhani',sans-serif;font-size:0.9rem;outline:none;transition:border 0.2s;}
.cf-group input:focus,.cf-group select:focus{border-color:#4facfe;}
.cf-group select option{background:#0d1f35;color:white;}

/* BUTTONS */
.btn{padding:9px 16px;border:none;border-radius:9px;cursor:pointer;font-family:'Rajdhani',sans-serif;font-weight:700;font-size:0.84rem;transition:all 0.2s;display:inline-flex;align-items:center;gap:6px;}
.btn-p{background:linear-gradient(135deg,#4facfe,#00c6ff);color:#050d1a;}
.btn-p:hover{opacity:0.88;transform:translateY(-1px);}
.btn-d{background:rgba(255,71,87,0.16);color:#ff6b81;border:1px solid rgba(255,71,87,0.28);}
.btn-d:hover{background:rgba(255,71,87,0.3);}
.btn-e{background:rgba(46,204,113,0.14);color:#2ecc71;border:1px solid rgba(46,204,113,0.28);}
.btn-e:hover{background:rgba(46,204,113,0.28);}
.btn-sm{padding:5px 10px;font-size:0.76rem;}

/* CLASS GRID */
.class-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px;}
.class-card{background:rgba(0,255,255,0.04);border:1px solid rgba(0,255,255,0.12);border-radius:14px;overflow:hidden;transition:box-shadow 0.2s;}
.class-card:hover{box-shadow:0 6px 24px rgba(0,255,255,0.07);}
.cc-head{background:rgba(79,172,254,0.1);padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid rgba(0,255,255,0.1);}
.cc-name{font-family:'Orbitron',sans-serif;font-size:0.82rem;color:#00ffff;letter-spacing:1px;font-weight:700;}
.cc-meta{font-size:0.7rem;color:rgba(0,255,255,0.45);margin-top:3px;}
.cc-actions{display:flex;gap:6px;flex-shrink:0;}
.cc-body{padding:14px 16px;}

/* RENAME FORM */
.rename-form{display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;}
.rename-form input{flex:1;min-width:140px;padding:7px 10px;background:rgba(0,0,0,0.4);border:1px solid rgba(0,255,255,0.18);border-radius:8px;color:rgba(255,255,255,0.86);font-family:'Rajdhani',sans-serif;font-size:0.86rem;outline:none;}
.rename-form input:focus{border-color:#4facfe;}

/* ENROLL FORM */
.enroll-form{display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;align-items:flex-end;}
.enroll-form select{flex:1;min-width:160px;padding:7px 10px;background:rgba(0,0,0,0.4);border:1px solid rgba(0,255,255,0.18);border-radius:8px;color:rgba(255,255,255,0.86);font-family:'Rajdhani',sans-serif;font-size:0.86rem;outline:none;}
.enroll-form select:focus{border-color:#4facfe;}
.enroll-form select option{background:#0d1f35;color:white;}

/* STUDENT LIST */
.student-list{display:flex;flex-direction:column;gap:6px;}
.student-row{display:flex;justify-content:space-between;align-items:center;padding:7px 10px;background:rgba(0,0,0,0.22);border-radius:8px;border:1px solid rgba(255,255,255,0.05);}
.student-row:hover{background:rgba(0,255,255,0.04);}
.sr-name{font-weight:700;font-size:0.84rem;color:rgba(255,255,255,0.82);}
.sr-email{font-size:0.7rem;color:rgba(255,255,255,0.35);margin-top:1px;}
.no-students{text-align:center;padding:18px 0;color:rgba(255,255,255,0.22);font-size:0.82rem;}
.no-students i{display:block;font-size:1.4rem;margin-bottom:6px;color:rgba(0,255,255,0.15);}

/* TOGGLE collapse */
.cc-toggle{cursor:pointer;background:none;border:none;color:rgba(0,255,255,0.5);font-size:0.75rem;padding:4px 8px;border-radius:6px;transition:0.2s;white-space:nowrap;}
.cc-toggle:hover{background:rgba(0,255,255,0.1);color:#00ffff;}
.cc-collapsible{display:none;}
.cc-collapsible.open{display:block;}

/* SECTION DIVIDER */
.sdiv{font-size:0.68rem;text-transform:uppercase;letter-spacing:1.5px;color:rgba(0,255,255,0.35);margin:10px 0 7px;display:flex;align-items:center;gap:7px;}
.sdiv::after{content:'';flex:1;height:1px;background:rgba(0,255,255,0.1);}

/* EMPTY STATE */
.empty-state{text-align:center;padding:50px 20px;color:rgba(255,255,255,0.25);}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;color:rgba(0,255,255,0.15);}
.empty-state p{font-size:0.9rem;}

/* RESPONSIVE */
@media(max-width:768px){
    .layout{flex-direction:column;}
    .sidebar{width:100%;min-width:unset;height:auto;flex-direction:row;overflow-x:auto;overflow-y:hidden;padding:8px;border-right:none;border-bottom:1px solid rgba(0,255,255,0.1);}
    .sb-brand,.sl,.sb-foot,.uname,.sub{display:none;}
    .sidebar a{flex:0 0 auto;padding:8px 11px;border-left:none;border-bottom:3px solid transparent;border-radius:6px;font-size:0.78rem;}
    .sidebar a.active,.sidebar a:hover{border-left:none;border-bottom:3px solid #4facfe;}
    .main{padding:12px;height:calc(100vh - 56px);}
    .class-grid{grid-template-columns:1fr;}
    .stats-grid{grid-template-columns:repeat(3,1fr);}
}
</style>
</head>
<body>
<div id="particles-js"></div>

<div class="layout">

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sb-brand">
        <i class="fas fa-shield-alt"></i>
        <h2>PAOPS</h2>
        <div class="sub">Admin Panel</div>
        <div class="uname"><i class="fas fa-circle" style="color:#2ecc71;font-size:0.4rem;vertical-align:middle;margin-right:3px;"></i><?php echo htmlspecialchars($teacher['fullname']); ?></div>
    </div>
    <span class="sl">Navigation</span>
    <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="manage_classes.php" class="active"><i class="fas fa-chalkboard"></i> My Classes <span class="bc"><?php echo $my_class_count; ?></span></a>
    <a href="admin_dashboard.php?section=grades"><i class="fas fa-graduation-cap"></i> Grades</a>
    <a href="admin_dashboard.php?section=activities"><i class="fas fa-tasks"></i> Activities</a>
    <a href="admin_dashboard.php?section=reminders"><i class="fas fa-bell"></i> Reminders</a>
    <a href="admin_dashboard.php?section=performance"><i class="fas fa-chart-line"></i> Performance</a>
    <div class="sb-foot">
        <a href="?logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <div class="tw"><i class="fas fa-chalkboard" style="color:#4facfe;margin-right:6px;"></i>Class Management</div>
            <div class="tm"><?php echo date('l, F j, Y'); ?> &mdash; <?php echo htmlspecialchars($teacher['fullname']); ?></div>
        </div>
        <a href="?logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- FLASH -->
    <?php if($flash): ?>
    <div class="flash <?php echo $flash_type; ?>">
        <i class="fas <?php echo $flash_type=='success'?'fa-check-circle':'fa-exclamation-circle'; ?>"></i>
        <?php echo $flash; ?>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="sc cb"><div class="si"><i class="fas fa-chalkboard"></i></div><div class="sv"><?php echo $my_class_count; ?></div><div class="slb">My Classes</div></div>
        <div class="sc cg"><div class="si"><i class="fas fa-user-check"></i></div><div class="sv"><?php echo $my_enroll_count; ?></div><div class="slb">Enrolled</div></div>
        <div class="sc ct"><div class="si"><i class="fas fa-users"></i></div><div class="sv"><?php echo $total_students; ?></div><div class="slb">Total Students</div></div>
    </div>

    <!-- CREATE CLASS -->
    <div class="create-card">
        <h3><i class="fas fa-plus-circle" style="color:#4facfe;margin-right:8px;"></i>Create New Class</h3>
        <form method="POST">
            <div class="create-form">
                <div class="cf-group">
                    <label>Class Name *</label>
                    <input type="text" name="class_name" placeholder="e.g. Mathematics 101, Science Grade 7..." required maxlength="100">
                </div>
                <button type="submit" name="create_class" class="btn btn-p" style="height:38px;align-self:flex-end;">
                    <i class="fas fa-plus"></i> Create Class
                </button>
            </div>
        </form>
    </div>

    <!-- CLASS LIST -->
    <div class="sec-title"><i class="fas fa-list"></i> My Classes (<?php echo $my_class_count; ?>)</div>

    <?php if($my_class_count == 0): ?>
    <div class="empty-state">
        <i class="fas fa-chalkboard"></i>
        <p>You haven't created any classes yet.<br>Use the form above to create your first class!</p>
    </div>
    <?php else: ?>
    <div class="class-grid">
    <?php
    $my_classes->data_seek(0);
    while($cls=$my_classes->fetch_assoc()):
        $cid  = $cls['id'];
        $cname= $cls['class_name'];
        $scnt = $cls['student_count'];
        $enrolled = $enrollments_by_class[(string)$cid] ?? [];
    ?>
    <div class="class-card">
        <!-- CLASS HEADER -->
        <div class="cc-head">
            <div>
                <div class="cc-name"><i class="fas fa-chalkboard-teacher" style="margin-right:7px;opacity:0.7;"></i><?php echo htmlspecialchars($cname); ?></div>
                <div class="cc-meta"><i class="fas fa-users" style="margin-right:4px;"></i><?php echo $scnt; ?> student<?php echo $scnt!=1?'s':''; ?> enrolled</div>
            </div>
            <div class="cc-actions">
                <button class="cc-toggle btn btn-sm" onclick="toggleCard(<?php echo $cid; ?>)">
                    <i class="fas fa-chevron-down" id="icon-<?php echo $cid; ?>"></i> Manage
                </button>
                <form method="POST" onsubmit="return confirm('Delete class \'<?php echo htmlspecialchars(addslashes($cname)); ?>\'? This will also remove all enrollments.');" style="display:inline;">
                    <input type="hidden" name="class_id" value="<?php echo $cid; ?>">
                    <button type="submit" name="delete_class" class="btn btn-d btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>

        <!-- COLLAPSIBLE BODY -->
        <div class="cc-collapsible" id="card-<?php echo $cid; ?>">
        <div class="cc-body">

            <!-- RENAME -->
            <div class="sdiv"><i class="fas fa-pencil-alt"></i> Rename Class</div>
            <form method="POST">
                <input type="hidden" name="class_id" value="<?php echo $cid; ?>">
                <div class="rename-form">
                    <input type="text" name="new_name" placeholder="New class name..." value="<?php echo htmlspecialchars($cname); ?>" required maxlength="100">
                    <button type="submit" name="rename_class" class="btn btn-p btn-sm"><i class="fas fa-save"></i> Rename</button>
                </div>
            </form>

            <!-- ENROLL STUDENT -->
            <div class="sdiv"><i class="fas fa-user-plus"></i> Enroll a Student</div>
            <form method="POST">
                <input type="hidden" name="class_id" value="<?php echo $cid; ?>">
                <div class="enroll-form">
                    <select name="student_id" required>
                        <option value="">-- Select Student --</option>
                        <?php
                        // List only students NOT already in this class
                        $enrolled_ids = array_column($enrolled, 'eid'); // not helpful here, need student ids
                        $already_enrolled_sids = array_map(function($e){ return $e['eid']; }, $enrolled);
                        $all_students->data_seek(0);
                        while($st=$all_students->fetch_assoc()):
                            // check if already enrolled
                            $is_enrolled = false;
                            foreach($enrolled as $en){
                                // we need student_id from enrollments — re-query per class would be heavy
                                // simpler: just show all, DB will reject duplicates
                            }
                        ?>
                        <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['fullname']); ?> — <?php echo htmlspecialchars($st['email']); ?></option>
                        <?php endwhile; ?>
                    </select>
                    <button type="submit" name="enroll_student" class="btn btn-e btn-sm"><i class="fas fa-user-plus"></i> Enroll</button>
                </div>
            </form>

            <!-- ENROLLED STUDENTS -->
            <div class="sdiv"><i class="fas fa-users"></i> Enrolled Students (<?php echo count($enrolled); ?>)</div>
            <div class="student-list">
            <?php if(empty($enrolled)): ?>
                <div class="no-students"><i class="fas fa-user-slash"></i>No students enrolled yet.</div>
            <?php else: foreach($enrolled as $en): ?>
                <div class="student-row">
                    <div>
                        <div class="sr-name"><i class="fas fa-user-graduate" style="color:#4facfe;margin-right:5px;font-size:0.78rem;"></i><?php echo htmlspecialchars($en['fullname']); ?></div>
                        <div class="sr-email"><?php echo htmlspecialchars($en['email']); ?></div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($en['fullname'])); ?> from this class?');" style="display:inline;">
                        <input type="hidden" name="enrollment_id" value="<?php echo $en['eid']; ?>">
                        <button type="submit" name="unenroll_student" class="btn btn-d btn-sm"><i class="fas fa-user-minus"></i> Remove</button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
            </div>

        </div>
        </div><!-- end collapsible -->
    </div>
    <?php endwhile; ?>
    </div><!-- end class-grid -->
    <?php endif; ?>

</div><!-- end .main -->
</div><!-- end .layout -->

<script src="https://cdn.jsdelivr.net/npm/particles.js"></script>
<script>
particlesJS('particles-js',{
    particles:{number:{value:65,density:{enable:true,value_area:900}},color:{value:"#4facfe"},shape:{type:"circle"},
    opacity:{value:0.25,random:true},size:{value:2.2,random:true},
    line_linked:{enable:true,distance:130,color:"#00c6ff",opacity:0.13,width:1},
    move:{enable:true,speed:1.3,direction:"none",random:true}},
    interactivity:{detect_on:"canvas",
    events:{onhover:{enable:true,mode:"grab"},onclick:{enable:true,mode:"push"}},
    modes:{grab:{distance:130,line_linked:{opacity:0.4}},push:{particles_nb:3}}},
    retina_detect:true
});

function toggleCard(id){
    const body = document.getElementById('card-'+id);
    const icon = document.getElementById('icon-'+id);
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    icon.className = isOpen ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}

// Auto-dismiss flash after 4s
setTimeout(()=>{
    const f=document.querySelector('.flash');
    if(f){f.style.transition='opacity 0.5s';f.style.opacity='0';}
},4000);

// Auto-open card if there was a flash (keep context visible)
<?php if($flash && $flash_type==='success'): ?>
// open first card if any
const firstCard = document.querySelector('.cc-collapsible');
if(firstCard){ /* don't auto-open, let user choose */ }
<?php endif; ?>
</script>
</body>
</html>