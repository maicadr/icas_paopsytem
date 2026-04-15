<?php
session_start();
include "db.php";

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
if(!isset($_SESSION['role'])||$_SESSION['role']!='user'){
    header("Location: index.php"); exit();
}
$user_id=(int)$_SESSION['user_id'];
$active_section=$_GET['section']??'overview';
$search=$_GET['search']??'';

/* USER INFO */
$s=$conn->prepare("SELECT fullname,email FROM people WHERE id=?");
$s->bind_param("i",$user_id); $s->execute();
$user=$s->get_result()->fetch_assoc();
if(!$user){ header("Location: index.php"); exit(); }

/* GRADES — stored as flat array, grouped in PHP later */
if($search){
    $s=$conn->prepare("
        SELECT c.class_name,
               COALESCE(g.prelim,0) AS prelim,
               COALESCE(g.midterm,0) AS midterm,
               COALESCE(g.final,0) AS final,
               COALESCE(g.average,0) AS average
        FROM enrollments e
        JOIN classes c ON c.id=e.class_id
        LEFT JOIN grades g ON g.enrollment_id=e.id
        WHERE e.student_id=? AND c.class_name LIKE ?
        ORDER BY c.class_name");
    $s->bind_param("is",$user_id,"%$search%");
} else {
    $s=$conn->prepare("
        SELECT c.class_name,
               COALESCE(g.prelim,0) AS prelim,
               COALESCE(g.midterm,0) AS midterm,
               COALESCE(g.final,0) AS final,
               COALESCE(g.average,0) AS average
        FROM enrollments e
        JOIN classes c ON c.id=e.class_id
        LEFT JOIN grades g ON g.enrollment_id=e.id
        WHERE e.student_id=?
        ORDER BY c.class_name");
    $s->bind_param("i",$user_id);
}
$s->execute();
$grades_arr=[];
$chart_labels=[]; $chart_prelim=[]; $chart_midterm=[]; $chart_final=[];
$res=$s->get_result();
while($r=$res->fetch_assoc()){
    $grades_arr[]=$r;
    $chart_labels[]=$r['class_name'];
    $chart_prelim[]=(float)$r['prelim'];
    $chart_midterm[]=(float)$r['midterm'];
    $chart_final[]=(float)$r['final'];
}

/* ACTIVITIES — grouped by class_name */
$s=$conn->prepare("
    SELECT c.class_name, a.activity_name, a.completed
    FROM enrollments e
    JOIN classes c ON c.id=e.class_id
    JOIN activities a ON a.enrollment_id=e.id
    WHERE e.student_id=?
    ORDER BY c.class_name, a.completed ASC, a.activity_name");
$s->bind_param("i",$user_id); $s->execute();
$acts_res=$s->get_result();
$acts_by_class=[];
while($r=$acts_res->fetch_assoc()){
    $acts_by_class[$r['class_name']][]=$r;
}

/* REMINDERS — split into global and per-class */
$s=$conn->prepare("
    SELECT r.id, r.title, r.body, r.target_role,
           r.due_date, r.created_at, r.class_id,
           c.class_name AS cls_name
    FROM reminders r
    LEFT JOIN classes c ON c.id=r.class_id
    WHERE (r.target_role='user' OR r.target_role='all')
      AND (
          r.class_id IS NULL
          OR r.class_id=0
          OR r.class_id IN (SELECT class_id FROM enrollments WHERE student_id=?)
      )
    ORDER BY r.created_at DESC");
$s->bind_param("i",$user_id); $s->execute();
$rems_res=$s->get_result();
$rems_global=[];
$rems_by_class=[];
while($r=$rems_res->fetch_assoc()){
    if(!empty($r['class_id'])){
        $key=$r['cls_name']??'Unknown';
        $rems_by_class[$key][]=$r;
    } else {
        $rems_global[]=$r;
    }
}
$total_reminders=count($rems_global)+array_sum(array_map('count',$rems_by_class));

/* MY CLASSES list */
$s=$conn->prepare("SELECT c.class_name FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=? ORDER BY c.class_name");
$s->bind_param("i",$user_id); $s->execute();
$res_classes=$s->get_result();

/* STATS */
$s=$conn->prepare("SELECT COUNT(*) c FROM enrollments WHERE student_id=?"); $s->bind_param("i",$user_id); $s->execute(); $total_classes=(int)$s->get_result()->fetch_assoc()['c'];
$s=$conn->prepare("SELECT COUNT(*) c FROM grades g JOIN enrollments e ON g.enrollment_id=e.id WHERE e.student_id=? AND g.average>=75"); $s->bind_param("i",$user_id); $s->execute(); $total_passed=(int)$s->get_result()->fetch_assoc()['c'];
$s=$conn->prepare("SELECT COUNT(*) c FROM grades g JOIN enrollments e ON g.enrollment_id=e.id WHERE e.student_id=? AND g.average>0 AND g.average<75"); $s->bind_param("i",$user_id); $s->execute(); $total_failed=(int)$s->get_result()->fetch_assoc()['c'];
$s=$conn->prepare("SELECT ROUND(AVG(g.average),1) gpa FROM grades g JOIN enrollments e ON g.enrollment_id=e.id WHERE e.student_id=? AND g.average>0"); $s->bind_param("i",$user_id); $s->execute(); $gpa=$s->get_result()->fetch_assoc()['gpa']??0;
$s=$conn->prepare("SELECT COUNT(*) c FROM activities a JOIN enrollments e ON a.enrollment_id=e.id WHERE e.student_id=? AND a.completed=1"); $s->bind_param("i",$user_id); $s->execute(); $acts_done=(int)$s->get_result()->fetch_assoc()['c'];
$s=$conn->prepare("SELECT COUNT(*) c FROM activities a JOIN enrollments e ON a.enrollment_id=e.id WHERE e.student_id=? AND a.completed=0"); $s->bind_param("i",$user_id); $s->execute(); $acts_pending=(int)$s->get_result()->fetch_assoc()['c'];

$acts_total=$acts_done+$acts_pending;
$act_pct=$acts_total>0?round(($acts_done/$acts_total)*100):0;
$gpa_pct=min(100,round((float)$gpa));
$overall=round($gpa_pct*0.7+$act_pct*0.3);

/* ANALYTICS chart data — averages per class, activity count */
$chart_avgs=[];
foreach($grades_arr as $r){
    $avg_calc = ($r['prelim']>0||$r['midterm']>0||$r['final']>0)
                ? round($r['prelim']*0.25+$r['midterm']*0.25+$r['final']*0.50,1)
                : 0;
    $chart_avgs[]=$avg_calc;
}
$chart_acts_done=[];
$chart_acts_total=[];
foreach($chart_labels as $cls){
    $d=0; $t=0;
    if(isset($acts_by_class[$cls])){
        foreach($acts_by_class[$cls] as $a){
            $t++; if($a['completed']) $d++;
        }
    }
    $chart_acts_done[]=$d;
    $chart_acts_total[]=$t;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard — PAOPS</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700&family=Rajdhani:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
/* ═══════════════════════════════════════
   THEME VARIABLES
═══════════════════════════════════════ */
:root {
  /* Dark theme (default) */
  --bg:          #050d1a;
  --card-bg:     rgba(0,255,255,0.04);
  --card-bg2:    rgba(5,15,35,0.85);
  --border:      rgba(0,255,255,0.12);
  --border2:     rgba(0,255,255,0.08);

  /* Main content text */
  --text-primary:   #e0f7ff;
  --text-secondary: rgba(200,230,255,0.75);
  --text-muted:     rgba(0,255,255,0.50);
  --text-dim:       rgba(180,210,255,0.60);

  /* Accent */
  --acc:  #00ffff;
  --ap:   #4facfe;

  /* Component specifics */
  --topbar-bg:    rgba(0,255,255,0.05);
  --row-hover:    rgba(0,255,255,0.04);
  --thead-bg:     rgba(79,172,254,0.06);
  --input-bg:     rgba(0,0,0,0.40);
  --input-border: rgba(0,255,255,0.20);
  --pbar-bg:      rgba(255,255,255,0.08);
  --grp-head-bg:  rgba(79,172,254,0.10);
  --ri-bg:        rgba(0,0,0,0.15);
  --modal-bg:     rgba(5,15,35,0.97);

  /* Semantic colour tokens — overridden per theme */
  --sec-title-color: #00ffff;
  --card-title-color: rgba(0,255,255,0.50);
  --grp-name-color:  #00ffff;
  --ri-title-color:  #00ffff;
  --ci-name-color:   #00ffff;
  --score-val-color: #00ffff;
  --chart-grid:      rgba(0,255,255,0.04);
  --gold-text:       #ffd32a;

  /* Sidebar — dark */
  --sb:              #020816;
  --sb-bdr:          rgba(0,255,255,0.12);
  --sb-txt:          rgba(200,225,255,0.65);
  --sb-txt-active:   #4facfe;
  --sb-label:        rgba(0,255,255,0.35);
  --sb-hover-bg:     rgba(79,172,254,0.10);
  --sb-active-bg:    rgba(79,172,254,0.15);
  --sb-brand-title:  #4facfe;
  --sb-brand-sub:    rgba(150,200,255,0.45);
  --sb-brand-name:   rgba(200,225,255,0.65);
}

[data-theme="light"] {
  --bg:          #f0f4ff;
  --card-bg:     #ffffff;
  --card-bg2:    #f8faff;
  --border:      rgba(79,100,220,0.20);
  --border2:     rgba(79,100,220,0.14);

  /* All text — dark readable tones, no cyan */
  --text-primary:   #0d1333;
  --text-secondary: #1e2a5e;
  --text-muted:     rgba(50,70,180,0.65);
  --text-dim:       rgba(30,42,94,0.72);

  /* Accent — rich blue replaces cyan */
  --acc:  #1a3bbf;
  --ap:   #2563eb;

  /* Layout */
  --topbar-bg:    #ffffff;
  --row-hover:    rgba(79,100,220,0.05);
  --thead-bg:     rgba(79,100,220,0.07);
  --input-bg:     #f5f8ff;
  --input-border: rgba(79,100,220,0.25);
  --pbar-bg:      rgba(0,0,0,0.08);
  --grp-head-bg:  rgba(37,99,235,0.08);
  --ri-bg:        rgba(79,100,220,0.04);
  --modal-bg:     #ffffff;

  /* Section title, card title colours via acc */
  --sec-title-color: #1a3bbf;
  --card-title-color: rgba(50,70,180,0.65);
  --grp-name-color:  #1a3bbf;
  --ri-title-color:  #1a3bbf;
  --ci-name-color:   #1a3bbf;
  --score-val-color: #1a3bbf;
  --chart-grid:      rgba(79,100,220,0.08);

  /* Badge gold text readable on light */
  --gold-text: #8a6500;

  /* Sidebar — deep navy so text stays white & readable */
  --sb:              #1e2a5e;
  --sb-bdr:          rgba(79,120,255,0.25);
  --sb-txt:          rgba(200,215,255,0.75);
  --sb-txt-active:   #ffffff;
  --sb-label:        rgba(140,170,255,0.55);
  --sb-hover-bg:     rgba(255,255,255,0.10);
  --sb-active-bg:    rgba(79,172,254,0.25);
  --sb-brand-title:  #7eb8ff;
  --sb-brand-sub:    rgba(150,185,255,0.55);
  --sb-brand-name:   rgba(200,215,255,0.70);
}

/* ═══════════════════════════════════════
   BASE
═══════════════════════════════════════ */
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body, html {
  height:100%;
  font-family:'Rajdhani',sans-serif;
  background:var(--bg);
  color:var(--text-primary);
  overflow:hidden;
  transition:background .3s, color .3s;
}
#particles-js { position:fixed;width:100%;height:100%;top:0;left:0;z-index:1; }
.layout { display:flex;height:100vh;position:relative;z-index:10; }

/* ═══════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════ */
.sidebar {
  width:230px;min-width:230px;flex-shrink:0;
  background:var(--sb);
  display:flex;flex-direction:column;
  padding:18px 12px;height:100vh;overflow-y:auto;
  border-right:1px solid var(--sb-bdr);z-index:20;
  transition:background .3s,border-color .3s;
}
.sb-brand { text-align:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--sb-bdr); }
.sb-brand .brand-icon { font-size:1.8rem;color:var(--sb-brand-title);filter:drop-shadow(0 0 8px rgba(79,172,254,.6)); }
.sb-brand h2 { font-family:'Orbitron',sans-serif;font-size:.95rem;color:var(--sb-brand-title);letter-spacing:3px;margin:6px 0 2px; }
.sb-brand .sub { font-size:.6rem;color:var(--sb-brand-sub);letter-spacing:2px;text-transform:uppercase; }
.sb-brand .uname { font-size:.78rem;color:var(--sb-brand-name);margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sl { font-size:.6rem;letter-spacing:2px;text-transform:uppercase;color:var(--sb-label);margin:12px 0 3px 5px; }
.sidebar a {
  color:var(--sb-txt);text-decoration:none;
  padding:9px 11px;display:flex;align-items:center;
  border-left:3px solid transparent;margin:2px 0;
  border-radius:8px;transition:.2s;
  font-size:.86rem;font-weight:600;white-space:nowrap;
}
.sidebar a i { margin-right:9px;width:15px;text-align:center;font-size:.82rem; }
.sidebar a:hover { background:var(--sb-hover-bg);border-left:3px solid rgba(79,172,254,.5);color:var(--sb-txt-active); }
.sidebar a.active { background:var(--sb-active-bg);border-left:3px solid var(--ap);color:var(--sb-txt-active); }
.bc { margin-left:auto;background:rgba(79,172,254,.22);color:#4facfe;font-size:.65rem;padding:1px 6px;border-radius:9px; }
.bc.red { background:rgba(255,107,129,.22);color:#ff6b81; }
.sb-foot { margin-top:auto;padding-top:12px;border-top:1px solid var(--sb-bdr); }
.sb-foot a { color:#ff6b81!important; }
.sb-foot a:hover { background:rgba(255,71,87,.15)!important;border-left:3px solid #ff4757!important;color:#ff4757!important; }

/* ═══════════════════════════════════════
   MAIN AREA
═══════════════════════════════════════ */
.main { flex:1;overflow-y:auto;padding:20px 24px;min-width:0;z-index:20; }

/* TOPBAR */
.topbar {
  display:flex;justify-content:space-between;align-items:center;
  background:var(--topbar-bg);border:1px solid var(--border);
  padding:10px 15px;border-radius:12px;margin-bottom:18px;gap:10px;
  transition:background .3s;
}
.topbar .tw { font-weight:700;font-size:.92rem;color:var(--text-primary); }
.topbar .tm { font-size:.7rem;color:var(--text-muted);margin-top:2px; }
.tb-right { display:flex;align-items:center;gap:8px; }
.logout-btn {
  text-decoration:none;background:#ff4757;color:#fff;
  padding:7px 12px;border-radius:8px;font-size:.8rem;font-weight:700;
  flex-shrink:0;transition:.2s;
}
.logout-btn:hover { background:#c0392b; }
.settings-btn {
  background:rgba(79,172,254,.14);border:1px solid rgba(79,172,254,.30);
  color:#4facfe;padding:7px 12px;border-radius:8px;font-size:.8rem;
  cursor:pointer;font-family:'Rajdhani',sans-serif;font-weight:700;
  display:inline-flex;align-items:center;gap:5px;transition:.2s;flex-shrink:0;
}
.settings-btn:hover { background:rgba(79,172,254,.25); }

/* SECTION */
.section { display:none; }
.section.active { display:block;animation:fadeUp .25s ease both; }
@keyframes fadeUp { from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);} }
.sec-title {
  font-family:'Orbitron',sans-serif;font-size:.88rem;letter-spacing:2px;
  color:var(--sec-title-color);margin-bottom:14px;padding-bottom:9px;
  border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;
}
.sec-title i { color:var(--ap); }

/* STATS GRID */
.stats-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:11px;margin-bottom:18px; }
.stat-card {
  background:var(--card-bg);border:1px solid var(--border);
  border-radius:12px;padding:14px 10px;text-align:center;transition:transform .2s,background .3s;
}
.stat-card:hover { transform:translateY(-2px); }
.stat-icon { font-size:1.4rem;margin-bottom:5px; }
.stat-val { font-size:1.7rem;font-weight:700;line-height:1; }
.stat-lbl { font-size:.62rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-top:3px; }
.c-blue  .stat-icon,.c-blue  .stat-val { color:#4facfe; }
.c-green .stat-icon,.c-green .stat-val { color:#2ecc71; }
.c-red   .stat-icon,.c-red   .stat-val { color:#ff6b81; }
.c-gold  .stat-icon,.c-gold  .stat-val { color:#ffd32a; }
.c-purple.stat-icon,.c-purple.stat-val { color:#a29bfe; }
.c-teal  .stat-icon,.c-teal  .stat-val { color:#00cec9; }
.c-purple .stat-icon,.c-purple .stat-val { color:#a29bfe; }
.c-teal .stat-icon,.c-teal .stat-val { color:#00cec9; }

/* CARD */
.card {
  background:var(--card-bg);border:1px solid var(--border2);
  border-radius:13px;padding:16px;margin-bottom:18px;
  overflow-x:auto;transition:background .3s;
}
.card-title { font-weight:700;font-size:.78rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:12px;display:flex;align-items:center;gap:7px; }

/* BADGES */
.badge { display:inline-block;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase; }
.b-green { background:rgba(46,204,113,.16);color:#2ecc71; }
.b-red   { background:rgba(255,107,129,.16);color:#ff6b81; }
.b-blue  { background:rgba(79,172,254,.16);color:#4facfe; }
.b-gold  { background:rgba(255,211,42,.16);color:#e6b800; }

/* TABLE */
table { width:100%;border-collapse:collapse;min-width:380px; }
th, td { padding:9px 11px;text-align:center;border-bottom:1px solid var(--border2);white-space:nowrap; }
th { color:#4facfe;font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;background:var(--thead-bg); }
td { color:var(--text-secondary);font-size:.86rem; }
tr:hover td { background:var(--row-hover); }
td:first-child, th:first-child { text-align:left; }

/* PROGRESS BARS */
.pbar { background:var(--pbar-bg);border-radius:20px;overflow:hidden;height:7px;margin-top:3px; }
.pbar-inner { height:7px;border-radius:20px; }
.pbar-green .pbar-inner { background:linear-gradient(90deg,#2ecc71,#00b894); }
.pbar-red   .pbar-inner { background:linear-gradient(90deg,#ff6b81,#ff4757); }
.pbar-blue  .pbar-inner { background:linear-gradient(90deg,#4facfe,#00c6ff); }
.pbar-gold  .pbar-inner { background:linear-gradient(90deg,#ffd32a,#f9ca24); }

/* GRADE COLOURS */
.avg-hi  { color:#2ecc71;font-weight:700; }
.avg-mid { color:#ffd32a;font-weight:700; }
.avg-lo  { color:#ff6b81;font-weight:700; }

/* SEARCH */
.search-bar {
  padding:8px 11px;border-radius:8px;
  border:1px solid var(--input-border);background:var(--input-bg);
  color:var(--text-primary);font-family:'Rajdhani',sans-serif;font-size:.88rem;
  width:250px;max-width:100%;outline:none;transition:border .2s,background .3s;
}
.search-bar:focus { border-color:#4facfe; }
.search-bar::placeholder { color:var(--text-muted); }
.search-btn {
  padding:8px 14px;border-radius:8px;border:none;
  background:linear-gradient(135deg,#4facfe,#00c6ff);
  color:#050d1a;font-family:'Rajdhani',sans-serif;font-weight:700;font-size:.86rem;cursor:pointer;
}

/* CHARTS */
.chart-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:18px; }
.chart-card { background:var(--card-bg);border:1px solid var(--border2);border-radius:13px;padding:16px; }
.chart-card h3 { font-size:.7rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:12px; }

/* CLASS ITEMS */
.class-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:18px; }
.class-item {
  background:var(--card-bg);border:1px solid var(--border);
  border-radius:12px;padding:14px;transition:transform .2s,background .3s;
}
.class-item:hover { transform:translateY(-2px); }
.ci-name { font-weight:700;color:var(--ci-name-color);font-size:.9rem; }

/* GROUP BLOCKS — grades / activities / reminders */
.grp-block { margin-bottom:18px;border-radius:13px;overflow:hidden;border:1px solid var(--border); }
.grp-head {
  background:var(--grp-head-bg);padding:12px 16px;
  display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);flex-wrap:wrap;
}
.grp-head.green { background:rgba(46,204,113,.08);border-color:rgba(46,204,113,.18); }
.grp-name { font-family:'Orbitron',sans-serif;font-size:.78rem;color:var(--grp-name-color);letter-spacing:1px;flex:1; }
.grp-name.green { color:#2ecc71; }
.grp-tag { font-size:.68rem;font-weight:700;padding:2px 9px;border-radius:9px; }
.grp-body { background:var(--card-bg);overflow-x:auto;padding:10px 14px; }
.grp-body table { min-width:340px; }
.grp-body table th:first-child,
.grp-body table td:first-child { text-align:left; }

/* REMINDER ITEMS */
.ri {
  background:var(--ri-bg);border:1px solid var(--border2);
  border-radius:10px;padding:12px 14px;margin-bottom:8px;
}
.ri.glb-ri { border-color:rgba(46,204,113,.20); }
.ri.cls-ri { border-color:rgba(79,172,254,.22);background:rgba(79,172,254,.04); }
.ri.overdue { border-color:rgba(255,71,87,.25);background:rgba(255,71,87,.04); }
.ri-title { font-weight:700;color:var(--ri-title-color);font-size:.9rem;display:flex;align-items:center;gap:7px;flex-wrap:wrap; }
.ri-body  { color:var(--text-secondary);font-size:.82rem;margin-top:4px;line-height:1.5; }
.ri-meta  { font-size:.68rem;color:var(--text-muted);margin-top:6px;display:flex;gap:12px;flex-wrap:wrap; }
.cls-tag { background:rgba(79,172,254,.18);color:#4facfe;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700; }
.all-tag { background:rgba(46,204,113,.16);color:#2ecc71;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700; }

/* SCORE CARD */
.score-card {
  background:rgba(79,172,254,.06);border:1px solid var(--border);
  border-radius:16px;padding:22px 24px;margin-bottom:18px;
  display:flex;align-items:center;gap:24px;flex-wrap:wrap;transition:background .3s;
}
.score-circle {
  width:90px;height:90px;border-radius:50%;border:4px solid #4facfe;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  flex-shrink:0;background:rgba(79,172,254,.08);
}
.sc-val { font-family:'Orbitron',sans-serif;font-size:1.4rem;font-weight:700;color:var(--sec-title-color);line-height:1; }
.sc-lbl { font-size:.6rem;color:var(--text-muted);letter-spacing:1px;margin-top:2px; }
.score-details { flex:1;min-width:200px; }
.score-details h3 { font-family:'Orbitron',sans-serif;font-size:.82rem;color:var(--sec-title-color);letter-spacing:2px;margin-bottom:12px; }
.score-row { display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:12px;font-size:.82rem; }
.sr-lbl { color:var(--text-muted);flex-shrink:0;width:140px; }
.sr-bar { flex:1; }
.sr-val { flex-shrink:0;width:42px;text-align:right;font-weight:700; }

/* ALERT */
.alert { border-radius:12px;padding:13px 16px;display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap; }
.alert-gold { background:rgba(255,211,42,.07);border:1px solid rgba(255,211,42,.22); }
.alert-gold .al-txt { font-weight:700;color:#e6b800;font-size:.9rem; }
.alert-gold .al-sub { font-size:.76rem;color:var(--text-muted);margin-top:2px; }
.alert a {
  margin-left:auto;padding:6px 12px;border-radius:8px;text-decoration:none;
  font-size:.8rem;font-weight:700;flex-shrink:0;
  background:rgba(255,211,42,.20);color:#e6b800;border:1px solid rgba(255,211,42,.30);
}

/* EMPTY */
.empty { text-align:center;padding:28px 0;color:var(--text-muted);font-size:.86rem; }
.empty i { font-size:1.8rem;margin-bottom:8px;display:block;opacity:.3; }

/* THEME MODAL */
.modal-overlay {
  display:none;position:fixed;inset:0;z-index:9999;
  background:rgba(0,0,0,.65);backdrop-filter:blur(5px);
  align-items:center;justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:var(--modal-bg);border:1px solid var(--border);
  border-radius:18px;padding:28px 30px;width:90%;max-width:360px;
  box-shadow:0 24px 64px rgba(0,0,0,.5);
}
.modal-head { display:flex;justify-content:space-between;align-items:center;margin-bottom:20px; }
.modal-head h3 { font-family:'Orbitron',sans-serif;font-size:.88rem;color:var(--sec-title-color);letter-spacing:2px; }
.modal-close { background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.1rem;padding:2px 6px;border-radius:6px; }
.modal-close:hover { color:var(--text-primary); }
.modal-label { font-size:.68rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:10px;display:block; }
.theme-grid { display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px; }
.theme-btn {
  padding:11px;border:2px solid var(--border);border-radius:10px;
  background:transparent;color:var(--text-secondary);
  font-family:'Rajdhani',sans-serif;font-weight:700;font-size:.88rem;
  cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:7px;
}
.theme-btn:hover { border-color:#4facfe;color:var(--title-color); }
.theme-btn.on    { border-color:#4facfe;background:rgba(79,172,254,.12);color:var(--title-color); }
.accent-row { display:flex;gap:10px;flex-wrap:wrap; }
.accent-dot { width:30px;height:30px;border-radius:50%;border:3px solid transparent;cursor:pointer;transition:.2s; }
.accent-dot:hover { transform:scale(1.15); }
.accent-dot.on { border-color:var(--text-primary);transform:scale(1.1); }

/* OVERVIEW REMINDER PREVIEW */
.preview-ri {
  border-radius:10px;padding:12px 15px;margin-bottom:9px;
  border:1px solid rgba(255,211,42,.22);background:rgba(255,211,42,.05);
}
.preview-ri .ri-title { color:var(--title-color); }

/* INFO NOTE */
.info-note { font-size:.8rem;color:var(--text-muted);margin-bottom:12px;display:flex;align-items:center;gap:7px; }
.info-note i { color:#4facfe;flex-shrink:0; }

@media(max-width:768px){
  .layout { flex-direction:column; }
  .sidebar { width:100%;min-width:unset;height:auto;flex-direction:row;overflow-x:auto;padding:8px;border-right:none;border-bottom:1px solid var(--sb-bdr); }
  .sb-brand,.sl,.sb-foot,.uname,.sub { display:none; }
  .sidebar a { flex:0 0 auto;padding:8px 11px;border-left:none;border-bottom:3px solid transparent;border-radius:6px;font-size:.78rem; }
  .sidebar a.active,.sidebar a:hover { border-left:none;border-bottom:3px solid #4facfe; }
  .main { padding:12px;height:calc(100vh - 58px); }
  .stats-grid { grid-template-columns:repeat(2,1fr); }
  .chart-grid { grid-template-columns:1fr; }
  .score-card { flex-direction:column; }
}
</style>
</head>
<body>
<div id="particles-js"></div>
<div class="layout">

<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar">
  <div class="sb-brand">
    <div class="brand-icon"><i class="fas fa-user-graduate"></i></div>
    <h2>PAOPS</h2>
    <div class="sub">Student Portal</div>
    <div class="uname">
      <i class="fas fa-circle" style="color:#2ecc71;font-size:.4rem;vertical-align:middle;margin-right:3px;"></i>
      <?php echo htmlspecialchars($user['fullname']); ?>
    </div>
  </div>

  <span class="sl">My Portal</span>
  <a href="?section=overview"    class="<?php echo $active_section=='overview'?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Overview</a>
  <a href="?section=classes"     class="<?php echo $active_section=='classes'?'active':''; ?>"><i class="fas fa-chalkboard"></i> My Classes <span class="bc"><?php echo $total_classes; ?></span></a>
  <span class="sl">Academic</span>
  <a href="?section=grades"      class="<?php echo $active_section=='grades'?'active':''; ?>"><i class="fas fa-graduation-cap"></i> Grades</a>
  <a href="?section=activities"  class="<?php echo $active_section=='activities'?'active':''; ?>"><i class="fas fa-tasks"></i> Activities
    <?php if($acts_pending>0): ?><span class="bc red"><?php echo $acts_pending; ?></span><?php endif; ?>
  </a>
  <a href="?section=reminders"   class="<?php echo $active_section=='reminders'?'active':''; ?>"><i class="fas fa-bell"></i> Reminders
    <?php if($total_reminders>0): ?><span class="bc"><?php echo $total_reminders; ?></span><?php endif; ?>
  </a>
  <a href="?section=performance" class="<?php echo $active_section=='performance'?'active':''; ?>"><i class="fas fa-chart-line"></i> My Performance</a>
  <a href="?section=analytics"   class="<?php echo $active_section=='analytics'?'active':''; ?>"><i class="fas fa-chart-bar"></i> Analytics</a>

  <div class="sb-foot">
    <a href="?logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>

<!-- ═══ MAIN ═══ -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div>
      <div class="tw"><i class="fas fa-user-graduate" style="color:var(--ap);margin-right:6px;"></i>Welcome back, <?php echo htmlspecialchars($user['fullname']); ?></div>
      <div class="tm"><?php echo date('l, F j, Y'); ?> &mdash; Student Portal</div>
    </div>
    <div class="tb-right">
      <button class="settings-btn" onclick="openModal()"><i class="fas fa-palette"></i> Settings</button>
      <a href="?logout" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>

<!-- ════════════════════ OVERVIEW ════════════════════ -->
<div class="section <?php echo $active_section=='overview'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-tachometer-alt"></i> My Overview</div>
  <div class="stats-grid">
    <div class="stat-card c-blue"><div class="stat-icon"><i class="fas fa-chalkboard"></i></div><div class="stat-val"><?php echo $total_classes; ?></div><div class="stat-lbl">My Classes</div></div>
    <div class="stat-card c-green"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-val"><?php echo $total_passed; ?></div><div class="stat-lbl">Passed</div></div>
    <div class="stat-card c-red"><div class="stat-icon"><i class="fas fa-times-circle"></i></div><div class="stat-val"><?php echo $total_failed; ?></div><div class="stat-lbl">Failed</div></div>
    <div class="stat-card c-teal"><div class="stat-icon"><i class="fas fa-star"></i></div><div class="stat-val"><?php echo $gpa?:'—'; ?></div><div class="stat-lbl">GPA</div></div>
    <div class="stat-card c-green"><div class="stat-icon"><i class="fas fa-clipboard-check"></i></div><div class="stat-val"><?php echo $acts_done; ?></div><div class="stat-lbl">Acts Done</div></div>
    <div class="stat-card c-gold"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-val"><?php echo $acts_pending; ?></div><div class="stat-lbl">Pending</div></div>
    <div class="stat-card c-purple"><div class="stat-icon"><i class="fas fa-bell"></i></div><div class="stat-val"><?php echo $total_reminders; ?></div><div class="stat-lbl">Reminders</div></div>
    <div class="stat-card <?php echo $overall>=75?'c-green':($overall>=60?'c-gold':'c-red'); ?>">
      <div class="stat-icon"><i class="fas fa-trophy"></i></div>
      <div class="stat-val"><?php echo $overall; ?>%</div>
      <div class="stat-lbl">Overall</div>
    </div>
  </div>

  <?php if($acts_pending>0): ?>
  <div class="alert alert-gold">
    <i class="fas fa-exclamation-triangle" style="color:#ffd32a;font-size:1.2rem;flex-shrink:0;"></i>
    <div>
      <div class="al-txt">You have <?php echo $acts_pending; ?> pending <?php echo $acts_pending==1?'activity':'activities'; ?>!</div>
      <div class="al-sub">Go to Activities to see what needs to be done.</div>
    </div>
    <a href="?section=activities">View <i class="fas fa-arrow-right"></i></a>
  </div>
  <?php endif; ?>

  <?php if(!empty($chart_labels)): ?>
  <div class="chart-grid">
    <div class="chart-card"><h3><i class="fas fa-chart-bar"></i> Grade Breakdown</h3><canvas id="gradeChart" height="210"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-chart-pie"></i> Activity Completion</h3><canvas id="actDonut" height="210"></canvas></div>
  </div>
  <?php endif; ?>

  <?php if(!empty($rems_global)): ?>
  <div class="preview-ri">
    <div class="ri-title">
      <i class="fas fa-bell" style="color:#ffd32a;"></i>
      <?php echo htmlspecialchars($rems_global[0]['title']); ?>
      <span class="badge b-gold" style="font-size:.58rem;">Latest</span>
    </div>
    <?php if(!empty($rems_global[0]['body'])): ?><div class="ri-body" style="margin-top:4px;"><?php echo htmlspecialchars(mb_strimwidth($rems_global[0]['body'],0,140,'...')); ?></div><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if($total_reminders>0): ?>
  <a href="?section=reminders" style="display:inline-flex;align-items:center;gap:6px;color:var(--ap);font-size:.82rem;text-decoration:none;margin-bottom:16px;"><i class="fas fa-arrow-right"></i> See all reminders</a>
  <?php endif; ?>
</div>

<!-- ════════════════════ MY CLASSES ════════════════════ -->
<div class="section <?php echo $active_section=='classes'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-chalkboard"></i> My Enrolled Classes</div>
  <div class="class-grid">
    <?php $cc=0; $res_classes->data_seek(0); while($row=$res_classes->fetch_assoc()): $cc++; ?>
    <div class="class-item">
      <div class="ci-name"><i class="fas fa-chalkboard-teacher" style="color:var(--ap);margin-right:6px;font-size:.82rem;"></i><?php echo htmlspecialchars($row['class_name']); ?></div>
    </div>
    <?php endwhile; ?>
  </div>
  <?php if($cc===0): ?><div class="empty"><i class="fas fa-chalkboard"></i>Not enrolled in any classes yet.<br>Contact your administrator.</div><?php endif; ?>
</div>

<!-- ════════════════════ GRADES — grouped by class ════════════════════ -->
<div class="section <?php echo $active_section=='grades'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-graduation-cap"></i> My Grades</div>
  <form method="GET" style="margin-bottom:14px;">
    <input type="hidden" name="section" value="grades">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <input type="text" name="search" class="search-bar" placeholder="Search subject..." value="<?php echo htmlspecialchars($search); ?>">
      <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
    </div>
  </form>

  <?php if(empty($grades_arr)): ?>
  <div class="empty"><i class="fas fa-graduation-cap"></i>No grades available yet.</div>
  <?php else:
    foreach($grades_arr as $row):
      $avg = floatval($row['average']);
      /* Re-compute using 25/25/50 formula in case DB has old values */
      if($row['prelim']>0 || $row['midterm']>0 || $row['final']>0){
        $avg_display = round($row['prelim']*0.25 + $row['midterm']*0.25 + $row['final']*0.50, 2);
      } else {
        $avg_display = 0;
      }
      $ac = $avg_display>=85?'avg-hi':($avg_display>=75?'avg-mid':'avg-lo');
      if(!$avg_display){ $badge='<span class="badge b-blue">No Grade Yet</span>'; $ac=''; }
      elseif($avg_display>=75) $badge='<span class="badge b-green"><i class="fas fa-check"></i> Passed</span>';
      else $badge='<span class="badge b-red"><i class="fas fa-times"></i> Failed</span>';
      $cls_bg  = $avg_display>=75?'rgba(46,204,113,.14)':'rgba(255,107,129,.14)';
      $cls_txt = $avg_display>=75?'#2ecc71':'#ff6b81';
  ?>
  <div class="grp-block">
    <div class="grp-head">
      <i class="fas fa-chalkboard-teacher" style="color:var(--ap);"></i>
      <span class="grp-name"><?php echo htmlspecialchars($row['class_name']); ?></span>
      <?php if($avg_display): ?><span class="grp-tag" style="background:<?php echo $cls_bg; ?>;color:<?php echo $cls_txt; ?>;">Avg: <?php echo number_format($avg_display,2); ?></span><?php endif; ?>
      <?php echo $badge; ?>
    </div>
    <div class="grp-body">
      <table>
        <tr><th>Prelim (25%)</th><th>Midterm (25%)</th><th>Final (50%)</th><th>Average</th><th>Grade Bar</th></tr>
        <tr>
          <td><?php echo $row['prelim']?number_format($row['prelim'],2):'—'; ?></td>
          <td><?php echo $row['midterm']?number_format($row['midterm'],2):'—'; ?></td>
          <td><?php echo $row['final']?number_format($row['final'],2):'—'; ?></td>
          <td class="<?php echo $ac; ?>"><?php echo $avg_display?number_format($avg_display,2):'—'; ?></td>
          <td style="min-width:90px;">
            <div class="pbar <?php echo $avg_display>=75?'pbar-green':'pbar-red'; ?>">
              <div class="pbar-inner" style="width:<?php echo min(100,$avg_display); ?>%;"></div>
            </div>
          </td>
        </tr>
      </table>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- ════════════════════ ACTIVITIES — grouped by class ════════════════════ -->
<div class="section <?php echo $active_section=='activities'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-tasks"></i> My Activities</div>

  <?php if(empty($acts_by_class)): ?>
  <div class="empty"><i class="fas fa-tasks"></i>No activities assigned yet.</div>
  <?php else:
    foreach($acts_by_class as $cls_name => $acts):
      $done_c = count(array_filter($acts, fn($a) => $a['completed']));
      $pend_c = count($acts) - $done_c;
  ?>
  <div class="grp-block">
    <div class="grp-head">
      <i class="fas fa-chalkboard-teacher" style="color:var(--ap);"></i>
      <span class="grp-name"><?php echo htmlspecialchars($cls_name); ?></span>
      <span class="grp-tag" style="background:rgba(46,204,113,.15);color:#2ecc71;"><?php echo $done_c; ?> done</span>
      <span class="grp-tag" style="background:rgba(255,211,42,.15);color:#c8960a;"><?php echo $pend_c; ?> pending</span>
    </div>
    <div class="grp-body">
      <table>
        <tr><th>Activity</th><th>Status</th></tr>
        <?php foreach($acts as $act): ?>
        <tr>
          <td><?php echo htmlspecialchars($act['activity_name']); ?></td>
          <td>
            <?php if($act['completed']): ?>
              <span class="badge b-green"><i class="fas fa-check"></i> Completed</span>
            <?php else: ?>
              <span class="badge b-gold"><i class="fas fa-clock"></i> Pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php if($acts_total>0): ?>
  <div class="card">
    <div class="card-title"><i class="fas fa-chart-pie"></i> Overall Activity Progress</div>
    <div style="display:flex;justify-content:space-between;font-size:.84rem;margin-bottom:6px;">
      <span style="color:var(--text-muted);"><?php echo $acts_done; ?> of <?php echo $acts_total; ?> completed</span>
      <span style="color:var(--ap);font-weight:700;"><?php echo $act_pct; ?>%</span>
    </div>
    <div class="pbar pbar-<?php echo $act_pct>=75?'green':($act_pct>=50?'blue':'red'); ?>" style="height:11px;border-radius:10px;">
      <div class="pbar-inner" style="width:<?php echo $act_pct; ?>%;height:11px;border-radius:10px;"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ════════════════════ REMINDERS — grouped ════════════════════ -->
<div class="section <?php echo $active_section=='reminders'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-bell"></i> Reminders from Admin</div>

  <?php if(!empty($rems_global)): ?>
  <div class="grp-block">
    <div class="grp-head green">
      <i class="fas fa-users" style="color:#2ecc71;"></i>
      <span class="grp-name green">All Students — General</span>
      <span class="grp-tag" style="background:rgba(46,204,113,.15);color:#2ecc71;"><?php echo count($rems_global); ?> reminder<?php echo count($rems_global)!=1?'s':''; ?></span>
    </div>
    <div style="padding:10px 14px;">
      <?php foreach($rems_global as $row):
        $is_new  = !empty($row['created_at']) && (time()-strtotime($row['created_at']))<86400*3;
        $overdue = !empty($row['due_date']) && strtotime($row['due_date'])<time();
        $ri_cls  = 'ri glb-ri'.($overdue?' overdue':'');
        $bell_color = $overdue?'#ff6b81':'#2ecc71';
      ?>
      <div class="<?php echo $ri_cls; ?>">
        <div class="ri-title">
          <i class="fas fa-bell" style="color:<?php echo $bell_color; ?>;"></i>
          <?php echo htmlspecialchars($row['title']); ?>
          <span class="all-tag"><i class="fas fa-users" style="margin-right:3px;"></i>All Students</span>
          <?php if($is_new && !$overdue): ?><span class="badge b-gold" style="font-size:.58rem;">New</span><?php endif; ?>
          <?php if($overdue): ?><span class="badge b-red" style="font-size:.58rem;">Overdue</span><?php endif; ?>
        </div>
        <?php if(!empty($row['body'])): ?><div class="ri-body"><?php echo nl2br(htmlspecialchars($row['body'])); ?></div><?php endif; ?>
        <div class="ri-meta">
          <?php if(!empty($row['due_date'])): ?>
          <span style="<?php echo $overdue?'color:#ff6b81;':''; ?>"><i class="fas fa-calendar-alt"></i> Due: <?php echo date('M j, Y',strtotime($row['due_date'])); ?></span>
          <?php endif; ?>
          <?php if(!empty($row['created_at'])): ?><span><i class="fas fa-clock"></i> <?php echo date('M j, Y g:i A',strtotime($row['created_at'])); ?></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach($rems_by_class as $cls_name => $rems): ?>
  <div class="grp-block">
    <div class="grp-head">
      <i class="fas fa-chalkboard-teacher" style="color:var(--ap);"></i>
      <span class="grp-name"><?php echo htmlspecialchars($cls_name); ?></span>
      <span class="grp-tag" style="background:rgba(79,172,254,.15);color:var(--ap);"><?php echo count($rems); ?> reminder<?php echo count($rems)!=1?'s':''; ?></span>
    </div>
    <div style="padding:10px 14px;">
      <?php foreach($rems as $row):
        $is_new  = !empty($row['created_at']) && (time()-strtotime($row['created_at']))<86400*3;
        $overdue = !empty($row['due_date']) && strtotime($row['due_date'])<time();
        $ri_cls  = 'ri cls-ri'.($overdue?' overdue':'');
        $bell_color = $overdue?'#ff6b81':'#4facfe';
      ?>
      <div class="<?php echo $ri_cls; ?>">
        <div class="ri-title">
          <i class="fas fa-bell" style="color:<?php echo $bell_color; ?>;"></i>
          <?php echo htmlspecialchars($row['title']); ?>
          <span class="cls-tag"><i class="fas fa-chalkboard" style="margin-right:3px;"></i><?php echo htmlspecialchars($cls_name); ?></span>
          <?php if($is_new && !$overdue): ?><span class="badge b-gold" style="font-size:.58rem;">New</span><?php endif; ?>
          <?php if($overdue): ?><span class="badge b-red" style="font-size:.58rem;">Overdue</span><?php endif; ?>
        </div>
        <?php if(!empty($row['body'])): ?><div class="ri-body"><?php echo nl2br(htmlspecialchars($row['body'])); ?></div><?php endif; ?>
        <div class="ri-meta">
          <?php if(!empty($row['due_date'])): ?>
          <span style="<?php echo $overdue?'color:#ff6b81;':''; ?>"><i class="fas fa-calendar-alt"></i> Due: <?php echo date('M j, Y',strtotime($row['due_date'])); ?></span>
          <?php endif; ?>
          <?php if(!empty($row['created_at'])): ?><span><i class="fas fa-clock"></i> <?php echo date('M j, Y g:i A',strtotime($row['created_at'])); ?></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if(empty($rems_global) && empty($rems_by_class)): ?>
  <div class="empty"><i class="fas fa-bell-slash"></i>No reminders for you yet.</div>
  <?php endif; ?>
</div>

<!-- ════════════════════ PERFORMANCE ════════════════════ -->
<div class="section <?php echo $active_section=='performance'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-chart-line"></i> My Performance</div>

  <div class="score-card">
    <div class="score-circle">
      <div class="sc-val"><?php echo $overall; ?>%</div>
      <div class="sc-lbl">Overall</div>
    </div>
    <div class="score-details">
      <h3>Performance Breakdown</h3>
      <div class="score-row">
        <span class="sr-lbl"><i class="fas fa-star" style="color:#ffd32a;margin-right:5px;"></i>GPA</span>
        <div class="sr-bar">
          <div class="pbar pbar-<?php echo $gpa>=75?'green':($gpa>=60?'gold':'red'); ?>">
            <div class="pbar-inner" style="width:<?php echo $gpa_pct; ?>%;"></div>
          </div>
        </div>
        <span class="sr-val <?php echo $gpa>=75?'avg-hi':($gpa>=60?'avg-mid':'avg-lo'); ?>"><?php echo $gpa?:'—'; ?></span>
      </div>
      <div class="score-row">
        <span class="sr-lbl"><i class="fas fa-tasks" style="color:var(--ap);margin-right:5px;"></i>Activities</span>
        <div class="sr-bar">
          <div class="pbar pbar-blue"><div class="pbar-inner" style="width:<?php echo $act_pct; ?>%;"></div></div>
        </div>
        <span class="sr-val" style="color:var(--ap);"><?php echo $act_pct; ?>%</span>
      </div>
      <div class="score-row">
        <?php $ppass=$total_classes>0?round(($total_passed/$total_classes)*100):0; ?>
        <span class="sr-lbl"><i class="fas fa-check-circle" style="color:#2ecc71;margin-right:5px;"></i>Subjects Passed</span>
        <div class="sr-bar">
          <div class="pbar pbar-green"><div class="pbar-inner" style="width:<?php echo $ppass; ?>%;"></div></div>
        </div>
        <span class="sr-val" style="color:#2ecc71;"><?php echo $total_passed; ?>/<?php echo $total_classes; ?></span>
      </div>
      <div style="margin-top:10px;font-size:.72rem;color:var(--text-muted);">Overall = 70% grades + 30% activity completion</div>
    </div>
  </div>

  <!-- Detailed grade table by class -->
  <div class="card">
    <div class="card-title"><i class="fas fa-table"></i> Detailed Grade Report — By Subject</div>
    <?php if(empty($grades_arr)): ?>
    <div class="empty"><i class="fas fa-graduation-cap"></i>No grades yet.</div>
    <?php else:
      foreach($grades_arr as $row):
        $avg_d = ($row['prelim']>0||$row['midterm']>0||$row['final']>0)
                 ? round($row['prelim']*0.25 + $row['midterm']*0.25 + $row['final']*0.50, 2)
                 : 0;
        $ac = $avg_d>=85?'avg-hi':($avg_d>=75?'avg-mid':'avg-lo');
        $bc = $avg_d>=75?'pbar-green':'pbar-red';
        if(!$avg_d){ $badge='<span class="badge b-blue">No Grade</span>'; $ac=''; $bc='pbar-blue'; }
        elseif($avg_d>=75) $badge='<span class="badge b-green"><i class="fas fa-check"></i> Passed</span>';
        else $badge='<span class="badge b-red"><i class="fas fa-times"></i> Failed</span>';
    ?>
    <div style="margin-bottom:14px;">
      <div style="font-family:'Orbitron',sans-serif;font-size:.74rem;color:var(--title-color);padding:8px 12px;background:var(--grp-head-bg);border-radius:8px 8px 0 0;border:1px solid var(--border);border-bottom:none;display:flex;align-items:center;gap:8px;">
        <i class="fas fa-chalkboard-teacher" style="color:var(--ap);"></i><?php echo htmlspecialchars($row['class_name']); ?>
      </div>
      <div style="overflow-x:auto;border:1px solid var(--border);border-radius:0 0 8px 8px;background:var(--card-bg);">
        <table style="min-width:380px;">
          <tr><th>Prelim (25%)</th><th>Midterm (25%)</th><th>Final (50%)</th><th>Average</th><th>Grade Bar</th><th>Status</th></tr>
          <tr>
            <td><?php echo $row['prelim']?number_format($row['prelim'],2):'—'; ?></td>
            <td><?php echo $row['midterm']?number_format($row['midterm'],2):'—'; ?></td>
            <td><?php echo $row['final']?number_format($row['final'],2):'—'; ?></td>
            <td class="<?php echo $ac; ?>"><?php echo $avg_d?number_format($avg_d,2):'—'; ?></td>
            <td style="min-width:80px;"><div class="pbar <?php echo $bc; ?>"><div class="pbar-inner" style="width:<?php echo min(100,$avg_d); ?>%;"></div></div></td>
            <td><?php echo $badge; ?></td>
          </tr>
        </table>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if(!empty($chart_labels)): ?>
  <div class="chart-grid">
    <div class="chart-card"><h3><i class="fas fa-chart-bar"></i> Prelim / Midterm / Final</h3><canvas id="perfBar" height="220"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-chart-pie"></i> Pass vs Fail</h3><canvas id="perfDonut" height="220"></canvas></div>
  </div>
  <?php endif; ?>
</div>

<!-- ════════════════════ ANALYTICS ════════════════════ -->
<div class="section <?php echo $active_section=='analytics'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-chart-bar"></i> Analytics</div>

  <!-- Stat summary cards -->
  <div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card c-blue"><div class="stat-icon"><i class="fas fa-chalkboard"></i></div><div class="stat-val"><?php echo $total_classes; ?></div><div class="stat-lbl">Enrolled</div></div>
    <div class="stat-card c-green"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-val"><?php echo $total_passed; ?></div><div class="stat-lbl">Passed</div></div>
    <div class="stat-card c-red"><div class="stat-icon"><i class="fas fa-times-circle"></i></div><div class="stat-val"><?php echo $total_failed; ?></div><div class="stat-lbl">Failed</div></div>
    <div class="stat-card c-teal"><div class="stat-icon"><i class="fas fa-star"></i></div><div class="stat-val"><?php echo $gpa?:'—'; ?></div><div class="stat-lbl">GPA</div></div>
    <div class="stat-card c-green"><div class="stat-icon"><i class="fas fa-clipboard-check"></i></div><div class="stat-val"><?php echo $acts_done; ?></div><div class="stat-lbl">Acts Done</div></div>
    <div class="stat-card c-gold"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-val"><?php echo $acts_pending; ?></div><div class="stat-lbl">Pending</div></div>
    <div class="stat-card <?php echo $overall>=75?'c-green':($overall>=60?'c-gold':'c-red'); ?>"><div class="stat-icon"><i class="fas fa-trophy"></i></div><div class="stat-val"><?php echo $overall; ?>%</div><div class="stat-lbl">Overall</div></div>
  </div>

  <!-- 4-chart grid matching admin analytics -->
  <div class="chart-grid">
    <div class="chart-card">
      <h3><i class="fas fa-chart-bar"></i> Grade Average per Subject</h3>
      <canvas id="an_avgBar" height="220"></canvas>
    </div>
    <div class="chart-card">
      <h3><i class="fas fa-chart-pie"></i> Pass vs Fail</h3>
      <canvas id="an_passFail" height="220"></canvas>
    </div>
    <div class="chart-card">
      <h3><i class="fas fa-tasks"></i> Activity Completion per Subject</h3>
      <canvas id="an_actBar" height="220"></canvas>
    </div>
    <div class="chart-card">
      <h3><i class="fas fa-chart-line"></i> Prelim / Midterm / Final Breakdown</h3>
      <canvas id="an_grouped" height="220"></canvas>
    </div>
  </div>
</div>

</div><!-- end .main -->
</div><!-- end .layout -->

<!-- ═══ THEME MODAL ═══ -->
<div class="modal-overlay" id="themeModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="fas fa-palette" style="margin-right:8px;"></i>Appearance</h3>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <span class="modal-label">Theme</span>
    <div class="theme-grid">
      <button class="theme-btn" id="btn-dark"  onclick="setTheme('dark')"><i class="fas fa-moon"></i> Dark</button>
      <button class="theme-btn" id="btn-light" onclick="setTheme('light')"><i class="fas fa-sun"></i> Light</button>
    </div>
    <span class="modal-label">Accent Color</span>
    <div class="accent-row">
      <?php foreach(['#4facfe','#a29bfe','#2ecc71','#fd79a8','#ffd32a','#00cec9','#e17055'] as $c): ?>
      <div class="accent-dot" style="background:<?php echo $c; ?>;" data-c="<?php echo $c; ?>" onclick="setAccent('<?php echo $c; ?>')"></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/particles.js"></script>
<script>
particlesJS('particles-js',{
  particles:{number:{value:65,density:{enable:true,value_area:900}},color:{value:"#4facfe"},shape:{type:"circle"},opacity:{value:0.22,random:true},size:{value:2.1,random:true},line_linked:{enable:true,distance:130,color:"#00c6ff",opacity:0.12,width:1},move:{enable:true,speed:1.2,direction:"none",random:true}},
  interactivity:{detect_on:"canvas",events:{onhover:{enable:true,mode:"grab"},onclick:{enable:true,mode:"push"}},modes:{grab:{distance:130,line_linked:{opacity:0.35}},push:{particles_nb:3}}},
  retina_detect:true
});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
/* ── THEME ENGINE ── */
const ROOT = document.documentElement;
function applyTheme(t) {
  ROOT.setAttribute('data-theme', t);
  localStorage.setItem('paops_theme', t);
  document.getElementById('btn-dark').classList.toggle('on', t==='dark');
  document.getElementById('btn-light').classList.toggle('on', t==='light');
}
function setTheme(t) { applyTheme(t); }
function applyAccent(c) {
  ROOT.style.setProperty('--ap', c);
  localStorage.setItem('paops_accent', c);
  document.querySelectorAll('.accent-dot').forEach(d => d.classList.toggle('on', d.dataset.c === c));
}
function setAccent(c) { applyAccent(c); }
function openModal()  { document.getElementById('themeModal').classList.add('open'); }
function closeModal() { document.getElementById('themeModal').classList.remove('open'); }
/* Init on load */
(function() {
  applyTheme(localStorage.getItem('paops_theme') || 'dark');
  applyAccent(localStorage.getItem('paops_accent') || '#4facfe');
})();

/* ── CHARTS ── */
const isDark = () => document.documentElement.getAttribute('data-theme') !== 'light';
const chartGridColor = () => isDark() ? 'rgba(0,255,255,0.05)' : 'rgba(79,100,220,0.10)';
const chartLabelColor = () => isDark() ? 'rgba(0,210,255,0.60)' : 'rgba(50,70,180,0.65)';
Chart.defaults.color = chartLabelColor();
Chart.defaults.borderColor = chartGridColor();
const CL = <?php echo json_encode($chart_labels); ?>;
const CP = <?php echo json_encode($chart_prelim); ?>;
const CM = <?php echo json_encode($chart_midterm); ?>;
const CF = <?php echo json_encode($chart_final); ?>;
const CA = <?php echo json_encode($chart_avgs); ?>;
const CAD = <?php echo json_encode($chart_acts_done); ?>;
const CAT = <?php echo json_encode($chart_acts_total); ?>;
const acDone = <?php echo intval($acts_done); ?>;
const acPend = <?php echo intval($acts_pending); ?>;
const psd    = <?php echo intval($total_passed); ?>;
const fld    = <?php echo intval($total_failed); ?>;
const noGrd  = <?php echo intval(max(0,$total_classes-$total_passed-$total_failed)); ?>;

const grpDatasets = [
  {label:'Prelim',  data:CP, backgroundColor:'rgba(79,172,254,.45)',  borderColor:'#4facfe', borderWidth:2, borderRadius:4},
  {label:'Midterm', data:CM, backgroundColor:'rgba(0,206,201,.45)',   borderColor:'#00cec9', borderWidth:2, borderRadius:4},
  {label:'Final',   data:CF, backgroundColor:'rgba(162,155,254,.45)', borderColor:'#a29bfe', borderWidth:2, borderRadius:4},
];

/* Stacked dataset for activity completion per subject */
const actStackDatasets = [
  {label:'Done',    data:CAD, backgroundColor:'rgba(46,204,113,.65)',  borderColor:'#2ecc71', borderWidth:2, borderRadius:4},
  {label:'Pending', data:CAT.map((t,i)=>t-CAD[i]), backgroundColor:'rgba(255,211,42,.55)', borderColor:'#ffd32a', borderWidth:2, borderRadius:4},
];

function mkBar(id, labels, data, color) {
  const el = document.getElementById(id); if(!el) return;
  const gc = chartGridColor(), lc = chartLabelColor();
  new Chart(el, {type:'bar', data:{labels, datasets:[{data, backgroundColor:color+'88', borderColor:color, borderWidth:2, borderRadius:5}]},
    options:{responsive:true, plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true, grid:{color:gc}, ticks:{color:lc}},
              x:{grid:{color:gc}, ticks:{color:lc}}}}});
}
function mkGrouped(id, labels, datasets) {
  const el = document.getElementById(id); if(!el) return;
  const gc = chartGridColor();
  new Chart(el, {type:'bar', data:{labels, datasets}, options:{responsive:true,
    plugins:{legend:{position:'bottom',labels:{padding:10,boxWidth:10,color:chartLabelColor()}}},
    scales:{y:{beginAtZero:true,max:100,grid:{color:gc},ticks:{color:chartLabelColor()}},
            x:{grid:{color:gc},ticks:{color:chartLabelColor()}}}}});
}
function mkGroupedStack(id, labels, datasets) {
  const el = document.getElementById(id); if(!el) return;
  const gc = chartGridColor(), lc = chartLabelColor();
  new Chart(el, {type:'bar', data:{labels, datasets}, options:{responsive:true,
    plugins:{legend:{position:'bottom',labels:{padding:10,boxWidth:10,color:lc}}},
    scales:{x:{stacked:true,grid:{color:gc},ticks:{color:lc}},
            y:{stacked:true,beginAtZero:true,grid:{color:gc},ticks:{color:lc}}}}});
}
function mkDonut(id, labels, data, colors) {
  const el = document.getElementById(id); if(!el) return;
  new Chart(el, {type:'doughnut', data:{labels, datasets:[{data, backgroundColor:colors.map(c=>c+'99'), borderColor:colors, borderWidth:2}]},
    options:{responsive:true, plugins:{legend:{position:'bottom',labels:{padding:10,boxWidth:10,color:chartLabelColor()}}}, cutout:'58%'}});
}

/* Overview & Performance charts */
mkGrouped('gradeChart', CL, grpDatasets);
mkGrouped('perfBar',    CL, grpDatasets);
mkDonut('actDonut',   ['Completed','Pending'],          [acDone, acPend], ['#2ecc71','#ffd32a']);
mkDonut('perfDonut',  ['Passed','Failed','No Grade'],   [psd, fld, noGrd],['#2ecc71','#ff6b81','#4facfe']);

/* Analytics charts */
mkBar('an_avgBar',     CL, CA,  '#4facfe');
mkDonut('an_passFail', ['Passed','Failed','No Grade'], [psd, fld, noGrd], ['#2ecc71','#ff6b81','#4facfe']);
mkGroupedStack('an_actBar', CL, actStackDatasets);
mkGrouped('an_grouped', CL, grpDatasets);
</script>
</body>
</html>