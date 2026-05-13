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
if(!isset($_SESSION['role'])||$_SESSION['role']!='admin'){ header("Location: index.php"); exit(); }
$admin_id=(int)$_SESSION['user_id'];

// ─── Helper: verify a class belongs to THIS admin ────────────────────────────
function ownedClass($conn, $class_id, $admin_id){
    $s=$conn->prepare("SELECT id FROM classes WHERE id=? AND teacher_id=?");
    $s->bind_param("ii",$class_id,$admin_id);
    $s->execute(); $s->store_result();
    return $s->num_rows>0;
}
// ─── Helper: verify an enrollment belongs to a class owned by THIS admin ─────
function ownedEnrollment($conn, $enrollment_id, $admin_id){
    $s=$conn->prepare("SELECT e.id FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.id=? AND c.teacher_id=?");
    $s->bind_param("ii",$enrollment_id,$admin_id);
    $s->execute(); $s->store_result();
    return $s->num_rows>0;
}
// ─── Helper: verify an activity belongs to THIS admin ────────────────────────
function ownedActivity($conn, $activity_id, $admin_id){
    $s=$conn->prepare("SELECT a.id FROM activities a JOIN enrollments e ON e.id=a.enrollment_id JOIN classes c ON c.id=e.class_id WHERE a.id=? AND c.teacher_id=?");
    $s->bind_param("ii",$activity_id,$admin_id);
    $s->execute(); $s->store_result();
    return $s->num_rows>0;
}
// ─── Helper: verify a reminder belongs to THIS admin ─────────────────────────
function ownedReminder($conn, $reminder_id, $admin_id){
    $s=$conn->prepare("SELECT id FROM reminders WHERE id=? AND created_by=?");
    $s->bind_param("ii",$reminder_id,$admin_id);
    $s->execute(); $s->store_result();
    return $s->num_rows>0;
}

// ════════════════════ POST HANDLERS ════════════════════

if(isset($_POST['add_class'])){
    $cname=trim($_POST['class_name']);
    if($cname!==''){
        $s=$conn->prepare("INSERT INTO classes(teacher_id,class_name) VALUES(?,?)");
        $s->bind_param("is",$admin_id,$cname);
        if($s->execute()){ $flash="Class '$cname' added!"; $ft='success'; }
        else { $flash="DB error: ".$conn->error; $ft='error'; }
    } else { $flash="Class name required."; $ft='error'; }
    header("Location: admin_dashboard.php?section=classes&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['delete_class'])){
    $cid=(int)$_POST['class_id'];
    if(ownedClass($conn,$cid,$admin_id)){
        $s=$conn->prepare("DELETE FROM classes WHERE id=? AND teacher_id=?");
        $s->bind_param("ii",$cid,$admin_id); $s->execute();
        $flash="Class deleted."; $ft='success';
    } else { $flash="Unauthorized."; $ft='error'; }
    header("Location: admin_dashboard.php?section=classes&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['enroll_student'])){
    $is_ajax=!empty($_POST['ajax']);
    $cid=(int)$_POST['class_id'];
    $sids=isset($_POST['student_ids'])?$_POST['student_ids']:[];
    if($cid===0||empty($sids)){
        $err="Please select a class and at least one student.";
        if($is_ajax){ header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$err]); exit(); }
        header("Location: admin_dashboard.php?section=classes&flash=".urlencode($err)."&ft=error"); exit();
    }
    if(!ownedClass($conn,$cid,$admin_id)){
        if($is_ajax){ header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'Unauthorized.']); exit(); }
        header("Location: admin_dashboard.php?section=classes&flash=Unauthorized.&ft=error"); exit();
    }
    $enrolled_count=0; $skip_count=0; $new_students=[];
    $chk=$conn->prepare("SELECT id FROM enrollments WHERE student_id=? AND class_id=?");
    $ins=$conn->prepare("INSERT INTO enrollments(student_id,class_id) VALUES(?,?)");
    foreach($sids as $sid_raw){
        $sid=(int)$sid_raw; if($sid===0) continue;
        $chk->bind_param("ii",$sid,$cid); $chk->execute(); $chk->store_result();
        if($chk->num_rows>0){ $skip_count++; $chk->free_result(); continue; }
        $chk->free_result();
        $ins->bind_param("ii",$sid,$cid); $ins->execute();
        $new_eid=$conn->insert_id;
        $ex=$conn->query("SELECT DISTINCT a.activity_name FROM activities a JOIN enrollments e ON a.enrollment_id=e.id WHERE e.class_id=$cid");
        if($ex&&$ex->num_rows>0){
            $ai=$conn->prepare("INSERT INTO activities(enrollment_id,activity_name,completed) VALUES(?,?,0)");
            while($act=$ex->fetch_assoc()){ $ai->bind_param("is",$new_eid,$act['activity_name']); $ai->execute(); }
        }
        // fetch full person info to return for DOM injection
        $pi=$conn->prepare("SELECT id,fullname,email FROM people WHERE id=?");
        $pi->bind_param("i",$sid); $pi->execute(); $pr=$pi->get_result()->fetch_assoc();
        if($pr) $new_students[]=['eid'=>$new_eid,'pid'=>$pr['id'],'fullname'=>$pr['fullname'],'email'=>$pr['email'],'class_id'=>$cid];
        $enrolled_count++;
    }
    $msg="$enrolled_count student(s) enrolled successfully.";
    if($skip_count>0) $msg.=" $skip_count already enrolled (skipped).";
    if($is_ajax){
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true,'msg'=>$msg,'enrolled'=>$new_students,'class_id'=>$cid]);
        exit();
    }
    header("Location: admin_dashboard.php?section=classes&flash=".urlencode($msg)."&ft=success"); exit();
}

if(isset($_POST['unenroll_student'])){
    $eid=(int)$_POST['enrollment_id'];
    if(ownedEnrollment($conn,$eid,$admin_id)){
        $s=$conn->prepare("DELETE FROM enrollments WHERE id=?");
        $s->bind_param("i",$eid); $s->execute();
        $flash="Student removed from class."; $ft='success';
    } else { $flash="Unauthorized."; $ft='error'; }
    header("Location: admin_dashboard.php?section=classes&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['update_student'])){
    $pid=(int)$_POST['person_id']; $fn=trim($_POST['fullname']); $em=trim($_POST['email']);
    // verify this student is enrolled in at least one of THIS admin's classes
    $chkOwn=$conn->prepare("SELECT e.id FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE e.student_id=? AND c.teacher_id=? LIMIT 1");
    $chkOwn->bind_param("ii",$pid,$admin_id); $chkOwn->execute(); $chkOwn->store_result();
    if($fn&&$em&&$chkOwn->num_rows>0){
        $s=$conn->prepare("UPDATE people SET fullname=?,email=? WHERE id=? AND role='user'");
        $s->bind_param("ssi",$fn,$em,$pid); $s->execute();
        $flash="Student info updated."; $ft='success';
    } else { $flash=$chkOwn->num_rows===0?"Unauthorized.":"Name and email required."; $ft='error'; }
    header("Location: admin_dashboard.php?section=classes&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['add_activity'])){
    $cid=(int)$_POST['class_id']; $aname=trim($_POST['activity_name']);
    if($cid&&$aname!==''){
        if(!ownedClass($conn,$cid,$admin_id)){ $flash="Unauthorized."; $ft='error'; }
        else {
            $enr=$conn->prepare("SELECT id FROM enrollments WHERE class_id=?");
            $enr->bind_param("i",$cid); $enr->execute(); $enr_res=$enr->get_result();
            if($enr_res->num_rows===0){ $flash="No students enrolled in that class yet."; $ft='error'; }
            else {
                $ins=$conn->prepare("INSERT INTO activities(enrollment_id,activity_name,completed) VALUES(?,?,0)");
                $cnt=0;
                while($row=$enr_res->fetch_assoc()){
                    $eid=$row['id'];
                    $dup=$conn->prepare("SELECT id FROM activities WHERE enrollment_id=? AND activity_name=?");
                    $dup->bind_param("is",$eid,$aname); $dup->execute(); $dup->store_result();
                    if($dup->num_rows===0){ $ins->bind_param("is",$eid,$aname); $ins->execute(); $cnt++; }
                }
                $flash="Activity '$aname' assigned to $cnt student(s)."; $ft='success';
            }
        }
    } else { $flash="Select a class and enter an activity name."; $ft='error'; }
    header("Location: admin_dashboard.php?section=activities&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['update_activity'])){
    $aid=(int)$_POST['activity_id']; $done=isset($_POST['completed'])?1:0;
    if(ownedActivity($conn,$aid,$admin_id)){
        $s=$conn->prepare("UPDATE activities SET completed=? WHERE id=?");
        $s->bind_param("ii",$done,$aid); $s->execute();
    }
    header("Location: admin_dashboard.php?section=activities"); exit();
}

if(isset($_POST['delete_activity'])){
    $aid=(int)$_POST['activity_id'];
    if(ownedActivity($conn,$aid,$admin_id)){
        $s=$conn->prepare("DELETE FROM activities WHERE id=?");
        $s->bind_param("i",$aid); $s->execute();
        $flash="Activity deleted."; $ft='success';
    } else { $flash="Unauthorized."; $ft='error'; }
    header("Location: admin_dashboard.php?section=activities&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['delete_class_activity'])){
    $cid=(int)$_POST['class_id'];
    $aname=$conn->real_escape_string(trim($_POST['activity_name']));
    if(ownedClass($conn,$cid,$admin_id)){
        $conn->query("DELETE a FROM activities a JOIN enrollments e ON a.enrollment_id=e.id WHERE e.class_id=$cid AND a.activity_name='$aname'");
        $flash="Activity removed from all students."; $ft='success';
    } else { $flash="Unauthorized."; $ft='error'; }
    header("Location: admin_dashboard.php?section=activities&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['add_reminder'])){
    $title=trim($_POST['reminder_title']); $body=trim($_POST['reminder_body']);
    $target=$_POST['target_role']??'user';
    $due=!empty($_POST['due_date'])?$_POST['due_date']:null;
    $rcid=!empty($_POST['reminder_class_id'])?(int)$_POST['reminder_class_id']:null;
    // if a class is specified it must belong to this admin
    if($rcid && !ownedClass($conn,$rcid,$admin_id)){ $rcid=null; }
    if($title){
        $col=$conn->query("SHOW COLUMNS FROM reminders LIKE 'class_id'");
        if($col&&$col->num_rows>0){
            $s=$conn->prepare("INSERT INTO reminders(title,body,target_role,class_id,due_date,created_by) VALUES(?,?,?,?,?,?)");
            $s->bind_param("sssisi",$title,$body,$target,$rcid,$due,$admin_id);
        } else {
            $s=$conn->prepare("INSERT INTO reminders(title,body,target_role,due_date,created_by) VALUES(?,?,?,?,?)");
            $s->bind_param("ssssi",$title,$body,$target,$due,$admin_id);
        }
        $s->execute(); $flash="Reminder posted."; $ft='success';
    } else { $flash="Title required."; $ft='error'; }
    header("Location: admin_dashboard.php?section=reminders&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['delete_reminder'])){
    $rid=(int)$_POST['reminder_id'];
    if(ownedReminder($conn,$rid,$admin_id)){
        $s=$conn->prepare("DELETE FROM reminders WHERE id=? AND created_by=?");
        $s->bind_param("ii",$rid,$admin_id); $s->execute();
        $flash="Reminder deleted."; $ft='success';
    } else { $flash="Unauthorized."; $ft='error'; }
    header("Location: admin_dashboard.php?section=reminders&flash=".urlencode($flash)."&ft=$ft"); exit();
}

if(isset($_POST['save_grades'])){
    $eid=(int)$_POST['enrollment_id'];
    if(ownedEnrollment($conn,$eid,$admin_id)){
        $p=floatval($_POST['prelim']); $m=floatval($_POST['midterm']); $f=floatval($_POST['final']);
        $avg=round(($p*0.25+$m*0.25+$f*0.50),2); $st=$avg>=75?'Passed':'Failed';
        $chk=$conn->prepare("SELECT id FROM grades WHERE enrollment_id=?");
        $chk->bind_param("i",$eid); $chk->execute(); $chk->store_result();
        if($chk->num_rows>0){
            $s=$conn->prepare("UPDATE grades SET prelim=?,midterm=?,final=?,average=?,status=? WHERE enrollment_id=?");
            $s->bind_param("dddisi",$p,$m,$f,$avg,$st,$eid);
        } else {
            $s=$conn->prepare("INSERT INTO grades(enrollment_id,prelim,midterm,final,average,status) VALUES(?,?,?,?,?,?)");
            $s->bind_param("idddis",$eid,$p,$m,$f,$avg,$st);
        }
        $s->execute();
        $flash="Grades saved."; $ft='success';
    } else { $flash="Unauthorized."; $ft='error'; }
    header("Location: admin_dashboard.php?section=grades&flash=".urlencode($flash)."&ft=$ft"); exit();
}

// ════════════════════ FETCH ADMIN INFO ════════════════════
$stmt=$conn->prepare("SELECT fullname FROM people WHERE id=?");
$stmt->bind_param("i",$admin_id); $stmt->execute();
$admin=$stmt->get_result()->fetch_assoc();
if(!$admin){ header("Location: index.php"); exit(); }

$active_section=$_GET['section']??'overview';
$search=$_GET['search']??'';
$flash=isset($_GET['flash'])?htmlspecialchars(urldecode($_GET['flash'])):'';
$flash_type=$_GET['ft']??'success';

// ════════════════════ DATA QUERIES — all scoped to $admin_id ════════════════════

// Students: only those enrolled in THIS admin's classes
if($search){
    $s=$conn->prepare("SELECT DISTINCT p.id,p.fullname,p.email FROM people p JOIN enrollments e ON e.student_id=p.id JOIN classes c ON c.id=e.class_id WHERE p.role='user' AND c.teacher_id=? AND p.fullname LIKE ? ORDER BY p.fullname");
    $lk="%$search%"; $s->bind_param("is",$admin_id,$lk);
} else {
    $s=$conn->prepare("SELECT DISTINCT p.id,p.fullname,p.email FROM people p JOIN enrollments e ON e.student_id=p.id JOIN classes c ON c.id=e.class_id WHERE p.role='user' AND c.teacher_id=? ORDER BY p.fullname");
    $s->bind_param("i",$admin_id);
}
$s->execute(); $students_arr=[]; $res=$s->get_result();
while($r=$res->fetch_assoc()) $students_arr[]=$r;

// All registered students (for the enroll picker — admins can enroll any user)
$all_students_res=$conn->prepare("SELECT id,fullname,email FROM people WHERE role='user' ORDER BY fullname");
$all_students_res->execute(); $all_students_arr=[];
$allr=$all_students_res->get_result();
while($r=$allr->fetch_assoc()) $all_students_arr[]=$r;

$total_students=count($students_arr);

// Classes — only this admin's
$classes_arr=[];
$res=$conn->prepare("SELECT id,class_name FROM classes WHERE teacher_id=? ORDER BY class_name");
$res->bind_param("i",$admin_id); $res->execute();
$cr=$res->get_result(); while($r=$cr->fetch_assoc()) $classes_arr[]=$r;
$total_classes=count($classes_arr);

// Enrollments grouped by class — only this admin's classes
$enrollments_by_class=[];
$eq=$conn->prepare("SELECT e.id AS eid,e.class_id,e.student_id,p.id AS pid,p.fullname,p.email,c.class_name FROM enrollments e JOIN people p ON p.id=e.student_id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? ORDER BY c.class_name,p.fullname");
$eq->bind_param("i",$admin_id); $eq->execute(); $eqr=$eq->get_result();
if($eqr){ while($r=$eqr->fetch_assoc()){ $enrollments_by_class[$r['class_id']][]=$r; } }

// Grades — only this admin's classes
$grades_raw=$conn->prepare("SELECT e.id AS enrollment_id,p.fullname,c.class_name,COALESCE(g.prelim,0) AS prelim,COALESCE(g.midterm,0) AS midterm,COALESCE(g.final,0) AS final,COALESCE(g.average,0) AS average,COALESCE(g.status,'') AS status FROM enrollments e JOIN people p ON p.id=e.student_id JOIN classes c ON c.id=e.class_id LEFT JOIN grades g ON g.enrollment_id=e.id WHERE c.teacher_id=? ORDER BY c.class_name,p.fullname");
$grades_raw->bind_param("i",$admin_id); $grades_raw->execute();
$grades_result=$grades_raw->get_result();
$grades_by_class=[];
if($grades_result){ while($r=$grades_result->fetch_assoc()){ $grades_by_class[$r['class_name']][]=$r; } }

// Activities — only this admin's classes
$result_activities=$conn->prepare("SELECT a.id AS activity_id,p.fullname,c.class_name,c.id AS class_id,a.activity_name,a.completed,e.id AS enrollment_id FROM enrollments e JOIN people p ON p.id=e.student_id JOIN classes c ON c.id=e.class_id JOIN activities a ON a.enrollment_id=e.id WHERE c.teacher_id=? ORDER BY c.class_name,a.activity_name,p.fullname");
$result_activities->bind_param("i",$admin_id); $result_activities->execute();
$result_activities=$result_activities->get_result();

// Reminders — only this admin's
$col=$conn->query("SHOW COLUMNS FROM reminders LIKE 'class_id'");
$has_class_id=($col&&$col->num_rows>0);
if($has_class_id){
    $rq=$conn->prepare("SELECT r.*,c.class_name AS cls_name FROM reminders r LEFT JOIN classes c ON c.id=r.class_id WHERE r.created_by=? ORDER BY r.created_at DESC");
} else {
    $rq=$conn->prepare("SELECT *,NULL AS cls_name,NULL AS class_id FROM reminders WHERE created_by=? ORDER BY created_at DESC");
}
$rq->bind_param("i",$admin_id); $rq->execute();
$result_reminders=$rq->get_result();

// Overview stats — scoped to this admin's classes
$total_enrollments=$conn->prepare("SELECT COUNT(*) c FROM enrollments e JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=?");
$total_enrollments->bind_param("i",$admin_id); $total_enrollments->execute();
$total_enrollments=$total_enrollments->get_result()->fetch_assoc()['c'];

$passed=$conn->prepare("SELECT COUNT(*) c FROM grades g JOIN enrollments e ON e.id=g.enrollment_id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? AND g.average>=75");
$passed->bind_param("i",$admin_id); $passed->execute(); $passed=$passed->get_result()->fetch_assoc()['c'];

$failed=$conn->prepare("SELECT COUNT(*) c FROM grades g JOIN enrollments e ON e.id=g.enrollment_id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? AND g.average>0 AND g.average<75");
$failed->bind_param("i",$admin_id); $failed->execute(); $failed=$failed->get_result()->fetch_assoc()['c'];

$comp_acts=$conn->prepare("SELECT COUNT(*) c FROM activities a JOIN enrollments e ON e.id=a.enrollment_id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? AND a.completed=1");
$comp_acts->bind_param("i",$admin_id); $comp_acts->execute(); $comp_acts=$comp_acts->get_result()->fetch_assoc()['c'];

$pend_acts=$conn->prepare("SELECT COUNT(*) c FROM activities a JOIN enrollments e ON e.id=a.enrollment_id JOIN classes c ON c.id=e.class_id WHERE c.teacher_id=? AND a.completed=0");
$pend_acts->bind_param("i",$admin_id); $pend_acts->execute(); $pend_acts=$pend_acts->get_result()->fetch_assoc()['c'];

$total_reminders_q=$conn->prepare("SELECT COUNT(*) c FROM reminders WHERE created_by=?");
$total_reminders_q->bind_param("i",$admin_id); $total_reminders_q->execute();
$total_reminders=$total_reminders_q->get_result()->fetch_assoc()['c'];

// Performance — students in THIS admin's classes
$perf_result=$conn->prepare("SELECT p.fullname,p.email,COUNT(DISTINCT e.class_id) AS classes_count,ROUND(AVG(g.average),1) AS gpa,SUM(CASE WHEN g.average>=75 THEN 1 ELSE 0 END) AS passed_count,SUM(CASE WHEN g.average>0 AND g.average<75 THEN 1 ELSE 0 END) AS failed_count,SUM(CASE WHEN a.completed=1 THEN 1 ELSE 0 END) AS acts_done,COUNT(a.id) AS acts_total FROM people p JOIN enrollments e ON e.student_id=p.id JOIN classes c ON c.id=e.class_id AND c.teacher_id=? LEFT JOIN grades g ON g.enrollment_id=e.id LEFT JOIN activities a ON a.enrollment_id=e.id WHERE p.role='user' GROUP BY p.id ORDER BY gpa DESC");
$perf_result->bind_param("i",$admin_id); $perf_result->execute();
$perf_result=$perf_result->get_result();

// Analytics charts — scoped to this admin's classes
$chart_classes=[]; $chart_avgs=[]; $enroll_per_class=[];
$tmp=$conn->prepare("SELECT c.class_name,ROUND(AVG(g.average),1) AS avg,COUNT(DISTINCT e.id) AS ecnt FROM classes c LEFT JOIN enrollments e ON e.class_id=c.id LEFT JOIN grades g ON g.enrollment_id=e.id WHERE c.teacher_id=? GROUP BY c.id ORDER BY c.class_name");
$tmp->bind_param("i",$admin_id); $tmp->execute(); $tmpr=$tmp->get_result();
while($r=$tmpr->fetch_assoc()){ $chart_classes[]=$r['class_name']; $chart_avgs[]=$r['avg']??0; $enroll_per_class[]=$r['ecnt']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — PAOPS</title>
<script>
/* ANTI-FOUC — runs before any CSS, eliminates theme flash on refresh */
(function(){
  try{
    var t=localStorage.getItem('paops_theme')||'dark';
    var c=localStorage.getItem('paops_accent')||'#4facfe';
    document.documentElement.setAttribute('data-theme',t);
    document.documentElement.style.setProperty('--ap',c);
  }catch(e){}
})();
</script>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700&family=Rajdhani:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
:root{
  --bg:#050d1a;--card:rgba(0,255,255,0.04);
  --bdr:rgba(0,255,255,0.10);--bdr2:rgba(0,255,255,0.09);
  --txt:#e0f7ff;--muted:rgba(0,255,255,0.45);--dim:rgba(255,255,255,0.55);
  --ap:#4facfe;--topbg:rgba(0,255,255,0.05);
  --rhov:rgba(0,255,255,0.03);--thead:rgba(79,172,254,0.05);--th:#4facfe;
  --ibg:rgba(0,0,0,0.45);--ibdr:rgba(0,255,255,0.18);--pbg:rgba(255,255,255,0.07);
  --title-color:#00ffff;--card-title-color:rgba(0,255,255,0.50);
  --grp-name-color:#00ffff;--ri-title-color:#00ffff;
  --cb-title-color:#00ffff;--modal-title-color:#00ffff;
  --act-done-bg:rgba(46,204,113,0.07);--act-done-bdr:rgba(46,204,113,0.22);
  --act-pend-bg:rgba(255,211,42,0.06);--act-pend-bdr:rgba(255,211,42,0.20);
  --act-row-bg:rgba(255,255,255,0.03);
  --sb:#020816;--sb-bdr:rgba(0,255,255,0.12);
  --sb-txt:rgba(200,225,255,0.65);--sb-txt-active:#4facfe;
  --sb-label:rgba(0,255,255,0.35);--sb-hover-bg:rgba(79,172,254,0.10);
  --sb-active-bg:rgba(79,172,254,0.15);--sb-brand-title:#4facfe;
  --sb-brand-sub:rgba(150,200,255,0.45);--sb-brand-name:rgba(200,225,255,0.65);
}
[data-theme="light"]{
  --bg:#eef2ff;--card:#ffffff;
  --bdr:rgba(79,122,254,0.18);--bdr2:rgba(79,122,254,0.13);
  --txt:#1a1f3c;--muted:rgba(60,80,180,0.6);--dim:rgba(26,31,60,0.65);
  --ap:#2563eb;--topbg:#ffffff;
  --rhov:rgba(79,122,254,0.04);--thead:rgba(79,122,254,0.06);--th:#2563eb;
  --ibg:#f5f8ff;--ibdr:rgba(79,122,254,0.22);--pbg:rgba(0,0,0,0.07);
  --title-color:#1a3bbf;--card-title-color:rgba(50,70,180,0.65);
  --grp-name-color:#1a3bbf;--ri-title-color:#1a3bbf;
  --cb-title-color:#1a3bbf;--modal-title-color:#1a3bbf;
  --act-done-bg:rgba(22,163,74,0.07);--act-done-bdr:rgba(22,163,74,0.24);
  --act-pend-bg:rgba(234,179,8,0.07);--act-pend-bdr:rgba(234,179,8,0.24);
  --act-row-bg:#f8faff;
  --sb:#1e2a5e;--sb-bdr:rgba(79,120,255,0.25);
  --sb-txt:rgba(200,215,255,0.75);--sb-txt-active:#ffffff;
  --sb-label:rgba(140,170,255,0.55);--sb-hover-bg:rgba(255,255,255,0.10);
  --sb-active-bg:rgba(79,172,254,0.25);--sb-brand-title:#7eb8ff;
  --sb-brand-sub:rgba(150,185,255,0.55);--sb-brand-name:rgba(200,215,255,0.70);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body,html{height:100%;font-family:'Rajdhani',sans-serif;background:var(--bg);color:var(--txt);overflow:hidden;transition:background .3s,color .3s;}
#particles-js{position:fixed;width:100%;height:100%;top:0;left:0;z-index:1;}
.layout{display:flex;height:100vh;position:relative;z-index:10;}
/* SIDEBAR */
.sidebar{width:235px;min-width:235px;flex-shrink:0;background:var(--sb);display:flex;flex-direction:column;padding:18px 12px;height:100vh;overflow-y:auto;border-right:1px solid var(--sb-bdr);z-index:20;transition:background .3s;}
.sb-brand{text-align:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--sb-bdr);}
.sb-brand i{font-size:1.8rem;color:var(--ap);filter:drop-shadow(0 0 8px rgba(79,172,254,.6));}
.sb-brand h2{font-family:'Orbitron',sans-serif;font-size:1rem;color:var(--sb-brand-title);letter-spacing:3px;margin:6px 0 2px;}
.sb-brand .sub{font-size:.62rem;color:var(--sb-brand-sub);letter-spacing:2px;text-transform:uppercase;}
.sb-brand .uname{font-size:.78rem;color:var(--sb-brand-name);margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sl{font-size:.6rem;letter-spacing:2px;text-transform:uppercase;color:var(--sb-label);margin:12px 0 3px 5px;}
.sidebar a{color:var(--sb-txt);text-decoration:none;padding:9px 11px;display:flex;align-items:center;border-left:3px solid transparent;margin:2px 0;border-radius:8px;transition:.2s;font-size:.86rem;font-weight:600;white-space:nowrap;}
.sidebar a i{margin-right:9px;width:15px;text-align:center;font-size:.82rem;}
.sidebar a:hover{background:var(--sb-hover-bg);border-left:3px solid rgba(79,172,254,.5);color:var(--sb-txt-active);}
.sidebar a.active{background:var(--sb-active-bg);border-left:3px solid var(--ap);color:var(--sb-txt-active);}
.sidebar a .bc{margin-left:auto;background:rgba(79,172,254,.2);color:var(--ap);font-size:.65rem;padding:1px 6px;border-radius:9px;}
.sb-foot{margin-top:auto;padding-top:12px;border-top:1px solid var(--sb-bdr);}
.sb-foot a{color:#ff4757!important;border-left:3px solid transparent!important;}
.sb-foot a:hover{background:rgba(255,71,87,.12)!important;border-left:3px solid #ff4757!important;color:#ff4757!important;}
/* MAIN */
.main{flex:1;overflow-y:auto;padding:20px 24px;min-width:0;z-index:20;}
.topbar{display:flex;justify-content:space-between;align-items:center;background:var(--topbg);border:1px solid var(--bdr);padding:10px 15px;border-radius:12px;margin-bottom:18px;gap:10px;transition:background .3s;}
.topbar .tw{font-weight:700;font-size:.92rem;}
.topbar .tm{font-size:.7rem;color:var(--muted);margin-top:2px;}
.tb-right{display:flex;align-items:center;gap:8px;}
.tb-right a{text-decoration:none;background:#ff4757;color:white;padding:7px 12px;border-radius:8px;font-size:.8rem;flex-shrink:0;}
.tb-right a:hover{background:#c0392b;}
.settings-btn{background:rgba(79,172,254,.12);border:1px solid rgba(79,172,254,.28);color:var(--ap);padding:7px 12px;border-radius:8px;font-size:.8rem;cursor:pointer;font-family:'Rajdhani',sans-serif;font-weight:700;display:inline-flex;align-items:center;gap:5px;transition:.2s;flex-shrink:0;}
.settings-btn:hover{background:rgba(79,172,254,.22);}
.flash{padding:9px 14px;border-radius:9px;margin-bottom:14px;font-weight:600;font-size:.88rem;display:flex;align-items:center;gap:8px;animation:fu .3s ease;}
.flash.success{background:rgba(46,204,113,.13);border:1px solid rgba(46,204,113,.3);color:#2ecc71;}
.flash.error{background:rgba(255,71,87,.13);border:1px solid rgba(255,71,87,.3);color:#ff6b81;}
@keyframes fu{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:11px;margin-bottom:18px;}
.stat-card{background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:14px 10px;text-align:center;transition:transform .2s,background .3s;}
.stat-card:hover{transform:translateY(-2px);}
.stat-icon{font-size:1.4rem;margin-bottom:5px;}.stat-val{font-size:1.75rem;font-weight:700;line-height:1;}
.stat-lbl{font-size:.62rem;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-top:3px;}
.c-blue .stat-icon,.c-blue .stat-val{color:var(--ap);}.c-green .stat-icon,.c-green .stat-val{color:#2ecc71;}
.c-red .stat-icon,.c-red .stat-val{color:#ff6b81;}.c-gold .stat-icon,.c-gold .stat-val{color:#ffd32a;}
.c-purple .stat-icon,.c-purple .stat-val{color:#a29bfe;}.c-teal .stat-icon,.c-teal .stat-val{color:#00cec9;}
.card{background:var(--card);border:1px solid var(--bdr2);border-radius:13px;padding:16px;margin-bottom:18px;overflow-x:auto;transition:background .3s;}
.card-title{font-weight:700;font-size:.8rem;text-transform:uppercase;letter-spacing:1px;color:var(--card-title-color);margin-bottom:13px;display:flex;align-items:center;gap:7px;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;align-items:end;}
.fg-full{grid-column:1/-1;}
.field label{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:4px;}
.field input,.field select,.field textarea{width:100%;padding:8px 10px;background:var(--ibg);border:1px solid var(--ibdr);border-radius:8px;color:var(--txt);font-family:'Rajdhani',sans-serif;font-size:.88rem;outline:none;transition:border .2s,background .3s;}
.field textarea{resize:vertical;min-height:68px;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--ap);}
.field select option{background:var(--sb);color:var(--txt);}
.btn{padding:8px 14px;border:none;border-radius:8px;cursor:pointer;font-family:'Rajdhani',sans-serif;font-weight:700;font-size:.82rem;transition:all .2s;display:inline-flex;align-items:center;gap:5px;}
.btn-primary{background:linear-gradient(135deg,#4facfe,#00c6ff);color:#050d1a;}
.btn-primary:hover{opacity:.88;transform:translateY(-1px);}
.btn-danger{background:rgba(255,71,87,.18);color:#ff6b81;border:1px solid rgba(255,71,87,.28);}
.btn-danger:hover{background:rgba(255,71,87,.32);}
.btn-save{background:rgba(79,172,254,.18);color:var(--ap);border:1px solid rgba(79,172,254,.28);}
.btn-save:hover{background:rgba(79,172,254,.32);}
.btn-warn{background:rgba(255,211,42,.16);color:#ffd32a;border:1px solid rgba(255,211,42,.28);}
.btn-warn:hover{background:rgba(255,211,42,.28);}
.btn-sm{padding:5px 9px;font-size:.75rem;}
table{width:100%;border-collapse:collapse;min-width:460px;}
th,td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--bdr);white-space:nowrap;}
th{color:var(--th);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;background:var(--thead);}
td{color:var(--dim);font-size:.86rem;}
tr:hover td{background:var(--rhov);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;}
.b-green{background:rgba(46,204,113,.14);color:#2ecc71;}.b-red{background:rgba(255,107,129,.14);color:#ff6b81;}
.b-blue{background:rgba(79,172,254,.14);color:var(--ap);}.b-gold{background:rgba(255,211,42,.14);color:#e6b800;}
[data-theme="light"] .b-gold{color:#8a6500;}
input[type="number"]{width:66px;padding:5px;border-radius:6px;border:1px solid var(--ibdr);background:var(--ibg);color:var(--ap);text-align:center;font-size:.83rem;outline:none;}
.toggle{display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:.8rem;color:var(--dim);}
input[type="checkbox"]{accent-color:var(--ap);width:14px;height:14px;cursor:pointer;}
.pbar{background:var(--pbg);border-radius:20px;overflow:hidden;height:7px;margin-top:3px;min-width:70px;}
.pbar-inner{height:7px;border-radius:20px;transition:width .4s;}
.pbar-green .pbar-inner{background:linear-gradient(90deg,#2ecc71,#00b894);}
.pbar-red .pbar-inner{background:linear-gradient(90deg,#ff6b81,#ff4757);}
.pbar-blue .pbar-inner{background:linear-gradient(90deg,#4facfe,#00c6ff);}
.chart-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:18px;}
.chart-card{background:var(--card);border:1px solid var(--bdr2);border-radius:13px;padding:16px;}
.chart-card h3{font-size:.72rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:12px;}
.section{display:none;}
.section.active{display:block;animation:fu .25s ease both;}
.sec-title{font-family:'Orbitron',sans-serif;font-size:.9rem;letter-spacing:2px;color:var(--title-color);margin-bottom:14px;padding-bottom:9px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:9px;}
.sec-title i{color:var(--ap);}
.avg-hi{color:#2ecc71;font-weight:700;}.avg-mid{color:#ffd32a;font-weight:700;}.avg-lo{color:#ff6b81;font-weight:700;}
.info-note{font-size:.8rem;color:var(--muted);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.info-note i{color:var(--ap);flex-shrink:0;}
/* GROUP BLOCKS */
.grp-block{margin-bottom:18px;border-radius:13px;overflow:hidden;border:1px solid var(--bdr);}
.grp-head{background:rgba(79,172,254,.1);padding:12px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;}
.grp-head.green{background:rgba(46,204,113,.08);border-color:rgba(46,204,113,.15);}
.grp-name{font-family:'Orbitron',sans-serif;font-size:.8rem;color:var(--grp-name-color);letter-spacing:1px;flex:1;}
.grp-name.green{color:#2ecc71;}
.grp-tag{font-size:.68rem;font-weight:700;padding:2px 9px;border-radius:9px;}
.grp-body{background:var(--card);overflow-x:auto;padding:12px 14px;}
.grp-body table td:first-child,
.grp-body table th:first-child {
    text-align: left;
    min-width: 140px;
}
.grp-body table td:not(:first-child),
.grp-body table th:not(:first-child) {
    text-align: center;
    width: 110px;
}

/* ═══ ACTIVITIES — REDESIGNED ═══ */
.act-class-block{margin-bottom:16px;border-radius:13px;overflow:hidden;border:1px solid var(--bdr);}
.act-class-head{background:rgba(79,172,254,.10);padding:13px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;}
.act-class-icon{width:36px;height:36px;border-radius:9px;background:rgba(79,172,254,.15);display:flex;align-items:center;justify-content:center;color:var(--ap);font-size:.95rem;flex-shrink:0;}
.act-class-name{font-family:'Orbitron',sans-serif;font-size:.8rem;color:var(--grp-name-color);letter-spacing:1px;flex:1;}
.act-tag{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:20px;}
.act-tag-done{background:rgba(46,204,113,.16);color:#2ecc71;}
.act-tag-pend{background:rgba(255,211,42,.16);color:#e6b800;}
[data-theme="light"] .act-tag-pend{color:#8a6500;}
.act-sub-wrap{padding:10px 14px;background:var(--card);display:flex;flex-direction:column;gap:10px;}
.act-sub{border-radius:10px;overflow:hidden;border:1px solid var(--bdr2);}
.act-sub-head{display:flex;align-items:center;gap:10px;padding:9px 14px;background:rgba(79,172,254,.07);border-bottom:1px solid var(--bdr2);flex-wrap:wrap;}
.act-sub-name{font-weight:700;font-size:.88rem;color:var(--title-color);flex:1;}
.act-sub-tag{font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:8px;}
/* ── per-student rows ── */
.act-list{background:var(--card);padding:8px 12px;display:flex;flex-direction:column;gap:6px;}
.act-row{
  display:flex;align-items:center;
  justify-content:space-between;
  gap:12px;padding:10px 14px;
  border-radius:9px;border:1px solid var(--bdr2);
  background:var(--act-row-bg);
  transition:border-color .15s,background .15s;
}
.act-row.done{background:var(--act-done-bg);border-color:var(--act-done-bdr);}
.act-row.pending{background:var(--act-pend-bg);border-color:var(--act-pend-bdr);}
.act-row-left{display:flex;align-items:center;gap:10px;flex:1;min-width:0;}
.act-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.act-row.done    .act-dot{background:#2ecc71;box-shadow:0 0 5px rgba(46,204,113,.55);}
.act-row.pending .act-dot{background:#ffd32a;box-shadow:0 0 5px rgba(255,211,42,.55);}
.act-student-name{font-weight:700;font-size:.86rem;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
/* status pill */
.act-status-pill{
  flex-shrink:0;
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 12px;border-radius:20px;
  font-size:.70rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.4px;
  white-space:nowrap;min-width:110px;justify-content:center;
}
.pill-done{background:rgba(46,204,113,.15);color:#2ecc71;border:1px solid rgba(46,204,113,.30);}
.pill-pend{background:rgba(255,211,42,.13);color:#e6b800;border:1px solid rgba(255,211,42,.28);}
[data-theme="light"] .pill-done{color:#16a34a;}
[data-theme="light"] .pill-pend{color:#8a6500;}
.act-row-actions{display:flex;align-items:center;gap:7px;flex-shrink:0;}

/* ENROLL */
.enroll-wrap{display:flex;flex-direction:column;gap:12px;}
.enroll-top{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.chk-list{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px;background:rgba(0,0,0,.10);border:1px solid var(--bdr);border-radius:12px;min-height:180px;}
.chk-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;border:1px solid transparent;cursor:pointer;transition:.15s;user-select:none;background:var(--card);}
.chk-row:hover{background:rgba(79,172,254,.07);border-color:rgba(79,172,254,.18);}
.chk-row.checked{background:rgba(79,172,254,.14);border-color:rgba(79,172,254,.40);}
.chk-row.pg-hidden{display:none;}
.chk-box{width:20px;height:20px;border-radius:5px;border:2px solid var(--bdr);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.15s;}
.chk-row.checked .chk-box{background:var(--ap);border-color:var(--ap);color:#fff;}
.chk-box i{font-size:.65rem;display:none;}
.chk-row.checked .chk-box i{display:block;}
.chk-row input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0;pointer-events:none;}
.chk-info{flex:1;min-width:0;}
.chk-name{display:block;font-weight:700;font-size:.86rem;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.chk-email{display:block;font-size:.70rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;}
.enroll-pg{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.pg-info{font-size:.80rem;color:var(--muted);font-weight:600;}
.pg-controls{display:flex;align-items:center;gap:6px;}
.pg-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdr);background:var(--card);color:var(--dim);cursor:pointer;font-size:.80rem;display:flex;align-items:center;justify-content:center;transition:.2s;font-family:'Rajdhani',sans-serif;font-weight:700;}
.pg-btn:hover:not(:disabled){background:rgba(79,172,254,.12);border-color:var(--ap);color:var(--ap);}
.pg-btn:disabled{opacity:.35;cursor:not-allowed;}
.pg-btn.active{background:var(--ap);border-color:var(--ap);color:#fff;}
.pg-dots{color:var(--muted);font-size:.80rem;padding:0 2px;}
.enroll-footer{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.sel-cnt{font-size:.82rem;color:var(--muted);font-weight:600;}
.chk-empty{grid-column:1/-1;text-align:center;padding:28px 0;color:var(--muted);font-size:.86rem;}
.chk-empty i{display:block;font-size:1.6rem;margin-bottom:8px;opacity:.35;}
@media(max-width:900px){.chk-list{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.chk-list{grid-template-columns:1fr;}}
/* ENROLLED MGMT */
.cls-block{background:rgba(0,0,0,.1);border:1px solid var(--bdr);border-radius:12px;margin-bottom:14px;overflow:hidden;}
.cls-block-head{background:rgba(79,172,254,.08);padding:11px 15px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--bdr);cursor:pointer;user-select:none;}
.cls-block-head:hover{background:rgba(79,172,254,.12);}
.cb-title{font-family:'Orbitron',sans-serif;font-size:.78rem;color:var(--cb-title-color);letter-spacing:1px;display:flex;align-items:center;gap:8px;}
.cb-cnt{background:rgba(79,172,254,.2);color:var(--ap);font-size:.65rem;padding:1px 7px;border-radius:9px;font-family:'Rajdhani',sans-serif;font-weight:700;}
.cls-block-body{padding:12px 15px;display:none;}
.cls-block-body.open{display:block;}
.smr-row{display:flex;align-items:center;gap:10px;padding:9px 11px;background:rgba(0,0,0,.1);border-radius:9px;border:1px solid var(--bdr);margin-bottom:6px;flex-wrap:wrap;}
.smr-row:hover{border-color:rgba(79,172,254,.2);background:rgba(79,172,254,.04);}
.smr-av{width:34px;height:34px;border-radius:50%;background:rgba(79,172,254,.18);display:flex;align-items:center;justify-content:center;color:var(--ap);font-size:.9rem;flex-shrink:0;}
.smr-info{flex:1;min-width:140px;}
.smr-name{font-weight:700;font-size:.88rem;color:var(--txt);}
.smr-email{font-size:.72rem;color:var(--muted);margin-top:1px;}
.smr-edit{display:none;width:100%;margin-top:8px;padding-top:8px;border-top:1px solid var(--bdr);}
.smr-edit.open{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;}
.smr-edit input{flex:1;min-width:140px;padding:6px 9px;background:var(--ibg);border:1px solid var(--ibdr);border-radius:7px;color:var(--txt);font-family:'Rajdhani',sans-serif;font-size:.86rem;outline:none;}
.smr-edit input:focus{border-color:var(--ap);}
.no-data{text-align:center;padding:22px 0;color:var(--muted);font-size:.84rem;}
.no-data i{display:block;font-size:1.5rem;margin-bottom:6px;opacity:.4;}
/* REMINDERS */
.ri{background:rgba(0,0,0,.08);border:1px solid var(--bdr2);border-radius:10px;padding:12px 14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
.ri.cls-ri{border-color:rgba(79,172,254,.22);background:rgba(79,172,254,.04);}
.ri.glb-ri{border-color:rgba(46,204,113,.18);}
[data-theme="light"] .ri{background:rgba(79,100,220,.03);}
.ri-title{font-weight:700;color:var(--ri-title-color);font-size:.9rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.ri-text{color:var(--dim);font-size:.8rem;margin-top:3px;line-height:1.5;}
.ri-meta{font-size:.68rem;color:var(--muted);margin-top:5px;display:flex;gap:12px;flex-wrap:wrap;}
.cls-tag{background:rgba(79,172,254,.18);color:var(--ap);padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;}
.all-tag{background:rgba(46,204,113,.14);color:#2ecc71;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;}
.perf-score{font-size:1.1rem;font-weight:700;}
/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--bdr);border-radius:18px;padding:28px 30px;width:90%;max-width:360px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.modal-head h3{font-family:'Orbitron',sans-serif;font-size:.88rem;color:var(--modal-title-color);letter-spacing:2px;}
.modal-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.1rem;}
.modal-label{font-size:.68rem;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px;display:block;}
.theme-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px;}
.theme-btn{padding:10px;border:2px solid var(--bdr);border-radius:10px;background:transparent;color:var(--dim);font-family:'Rajdhani',sans-serif;font-weight:700;font-size:.86rem;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:6px;}
.theme-btn:hover,.theme-btn.on{border-color:var(--ap);color:var(--title-color);background:rgba(79,172,254,.1);}
.accent-row{display:flex;gap:10px;flex-wrap:wrap;}
.accent-dot{width:30px;height:30px;border-radius:50%;border:3px solid transparent;cursor:pointer;transition:.2s;}
.accent-dot.on{border-color:var(--txt);}
@media(max-width:768px){
  .layout{flex-direction:column;}
  .sidebar{width:100%;min-width:unset;height:auto;flex-direction:row;overflow-x:auto;padding:8px;border-right:none;border-bottom:1px solid var(--sb-bdr);}
  .sb-brand,.sl,.sb-foot,.uname,.sub{display:none;}
  .sidebar a{flex:0 0 auto;padding:8px 11px;border-left:none;border-bottom:3px solid transparent;border-radius:6px;font-size:.78rem;}
  .sidebar a.active{border-left:none!important;border-bottom:3px solid var(--ap)!important;}
  .main{padding:12px;height:calc(100vh - 58px);}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .chart-grid{grid-template-columns:1fr;}
  .act-row{flex-wrap:wrap;}
}

.grp-body table th:nth-child(1), .grp-body table td:nth-child(1) { width:22%; text-align:left; }
.grp-body table th:nth-child(2), .grp-body table td:nth-child(2),
.grp-body table th:nth-child(3), .grp-body table td:nth-child(3),
.grp-body table th:nth-child(4), .grp-body table td:nth-child(4) { width:13%; }
.grp-body table th:nth-child(5), .grp-body table td:nth-child(5) { width:12%; }
.grp-body table th:nth-child(6), .grp-body table td:nth-child(6) { width:14%; }
.grp-body table th:nth-child(7), .grp-body table td:nth-child(7) { width:10%; }
</style>
</head>
<body>
<div id="particles-js"></div>
<div class="layout">

<div class="sidebar">
  <div class="sb-brand">
    <i class="fas fa-shield-alt"></i>
    <h2>PAOPS</h2>
    <div class="sub">Admin Panel</div>
    <div class="uname"><i class="fas fa-circle" style="color:#2ecc71;font-size:.4rem;vertical-align:middle;margin-right:3px;"></i><?php echo htmlspecialchars($admin['fullname']); ?></div>
  </div>
  <span class="sl">Main</span>
  <a href="?section=overview"    class="<?php echo $active_section=='overview'?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Overview</a>
  <a href="?section=students"    class="<?php echo $active_section=='students'?'active':''; ?>"><i class="fas fa-users"></i> Students <span class="bc"><?php echo $total_students; ?></span></a>
  <span class="sl">Academic</span>
  <a href="?section=classes"     class="<?php echo $active_section=='classes'?'active':''; ?>"><i class="fas fa-chalkboard"></i> Classes <span class="bc"><?php echo $total_classes; ?></span></a>
  <a href="?section=grades"      class="<?php echo $active_section=='grades'?'active':''; ?>"><i class="fas fa-graduation-cap"></i> Grades</a>
  <a href="?section=activities"  class="<?php echo $active_section=='activities'?'active':''; ?>"><i class="fas fa-tasks"></i> Activities</a>
  <a href="?section=reminders"   class="<?php echo $active_section=='reminders'?'active':''; ?>"><i class="fas fa-bell"></i> Reminders <span class="bc"><?php echo $total_reminders; ?></span></a>
  <span class="sl">Reports</span>
  <a href="?section=performance" class="<?php echo $active_section=='performance'?'active':''; ?>"><i class="fas fa-chart-line"></i> Performance</a>
  <a href="?section=analytics"   class="<?php echo $active_section=='analytics'?'active':''; ?>"><i class="fas fa-chart-bar"></i> Analytics</a>
  <div class="sb-foot"><a href="?logout"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
</div>

<div class="main">
<div class="topbar">
  <div>
    <div class="tw"><i class="fas fa-user-shield" style="color:var(--ap);margin-right:6px;"></i>Welcome, <?php echo htmlspecialchars($admin['fullname']); ?></div>
    <div class="tm"><?php echo date('l, F j, Y'); ?> &mdash; Administrator</div>
  </div>
  <div class="tb-right">
    <button class="settings-btn" onclick="openModal()"><i class="fas fa-palette"></i> Settings</button>
    <a href="?logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>
<?php if($flash): ?>
<div class="flash <?php echo htmlspecialchars($flash_type); ?>">
  <i class="fas <?php echo $flash_type=='success'?'fa-check-circle':'fa-exclamation-circle'; ?>"></i>
  <?php echo $flash; ?>
</div>
<?php endif; ?>

<!-- OVERVIEW -->
<div class="section <?php echo $active_section=='overview'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-tachometer-alt"></i> Dashboard Overview</div>
  <div class="stats-grid">
    <div class="stat-card c-blue"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-val"><?php echo $total_students; ?></div><div class="stat-lbl">My Students</div></div>
    <div class="stat-card c-blue"><div class="stat-icon"><i class="fas fa-chalkboard"></i></div><div class="stat-val"><?php echo $total_classes; ?></div><div class="stat-lbl">My Classes</div></div>
    <div class="stat-card c-teal"><div class="stat-icon"><i class="fas fa-user-check"></i></div><div class="stat-val"><?php echo $total_enrollments; ?></div><div class="stat-lbl">Enrollments</div></div>
    <div class="stat-card c-green"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-val"><?php echo $passed; ?></div><div class="stat-lbl">Passed</div></div>
    <div class="stat-card c-red"><div class="stat-icon"><i class="fas fa-times-circle"></i></div><div class="stat-val"><?php echo $failed; ?></div><div class="stat-lbl">Failed</div></div>
    <div class="stat-card c-green"><div class="stat-icon"><i class="fas fa-clipboard-check"></i></div><div class="stat-val"><?php echo $comp_acts; ?></div><div class="stat-lbl">Acts Done</div></div>
    <div class="stat-card c-gold"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-val"><?php echo $pend_acts; ?></div><div class="stat-lbl">Pending</div></div>
    <div class="stat-card c-purple"><div class="stat-icon"><i class="fas fa-bell"></i></div><div class="stat-val"><?php echo $total_reminders; ?></div><div class="stat-lbl">Reminders</div></div>
  </div>
  <div class="chart-grid">
    <div class="chart-card"><h3><i class="fas fa-chart-bar"></i> Class Averages</h3><canvas id="barA" height="200"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-chart-pie"></i> Pass / Fail</h3><canvas id="doA" height="200"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-tasks"></i> Activity Status</h3><canvas id="actA" height="200"></canvas></div>
  </div>
</div>

<!-- STUDENTS -->
<div class="section <?php echo $active_section=='students'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-users"></i> My Students</div>
  <form method="GET" style="margin-bottom:13px;"><input type="hidden" name="section" value="students">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <input type="text" name="search" placeholder="Search by name..." value="<?php echo htmlspecialchars($search); ?>" style="padding:8px 11px;border-radius:8px;border:1px solid var(--ibdr);background:var(--ibg);color:var(--txt);font-family:'Rajdhani',sans-serif;font-size:.88rem;outline:none;width:250px;">
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    </div>
  </form>
  <div class="card"><table>
    <tr><th>#</th><th>Full Name</th><th>Email</th></tr>
    <?php foreach($students_arr as $i=>$row): ?>
    <tr><td><?php echo $i+1; ?></td><td style="text-align:left;"><?php echo htmlspecialchars($row['fullname']); ?></td><td><?php echo htmlspecialchars($row['email']); ?></td></tr>
    <?php endforeach; ?>
    <?php if(empty($students_arr)): ?><tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px;">No students found in your classes.</td></tr><?php endif; ?>
  </table></div>
</div>

<!-- CLASSES -->
<div class="section <?php echo $active_section=='classes'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-chalkboard"></i> My Classes</div>
  <div class="card">
    <div class="card-title"><i class="fas fa-plus-circle"></i> Add New Class</div>
    <form method="POST" action="admin_dashboard.php">
      <div class="form-grid">
        <div class="field"><label>Class Name *</label><input type="text" name="class_name" placeholder="e.g. IS104 2B" required maxlength="100"></div>
        <div class="field" style="display:flex;align-items:flex-end;"><button type="submit" name="add_class" value="1" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fas fa-plus"></i> Add Class</button></div>
      </div>
    </form>
  </div>
  <div class="card">
    <div class="card-title"><i class="fas fa-user-plus"></i> Enroll Students in My Classes</div>
    <p class="info-note"><i class="fas fa-info-circle"></i> Pick one of your classes, tick students, click <strong>Enroll Selected</strong>. Already-enrolled are skipped.</p>
    <form id="enrollForm">
      <div class="enroll-wrap">
        <div class="enroll-top">
          <div class="field" style="flex:1;min-width:200px;"><label>Class *</label>
            <select name="class_id" required>
              <option value="">-- Select Class --</option>
              <?php foreach($classes_arr as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['class_name']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:1;min-width:200px;"><label>Search Student</label>
            <input type="text" id="stu_s" placeholder="Type name to filter..." oninput="enrollSearch(this.value)" autocomplete="off" style="padding:8px 10px;background:var(--ibg);border:1px solid var(--ibdr);border-radius:8px;color:var(--txt);font-family:'Rajdhani',sans-serif;font-size:.88rem;width:100%;outline:none;">
          </div>
          <div style="display:flex;align-items:flex-end;gap:7px;flex-shrink:0;">
            <button type="button" class="btn btn-primary btn-sm" onclick="pickAll(true)"><i class="fas fa-check-double"></i> All</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="pickAll(false)"><i class="fas fa-times"></i> None</button>
          </div>
        </div>
        <div class="chk-list" id="chk_list">
          <?php if(empty($all_students_arr)): ?>
          <div class="chk-empty"><i class="fas fa-users"></i>No students registered yet.</div>
          <?php else: foreach($all_students_arr as $st): ?>
          <div class="chk-row" data-name="<?php echo strtolower(htmlspecialchars($st['fullname'])); ?>" onclick="toggleChk(this)">
            <input type="checkbox" name="student_ids[]" value="<?php echo $st['id']; ?>">
            <div class="chk-box"><i class="fas fa-check"></i></div>
            <div class="chk-info">
              <span class="chk-name"><?php echo htmlspecialchars($st['fullname']); ?></span>
              <span class="chk-email"><?php echo htmlspecialchars($st['email']); ?></span>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
        <div class="enroll-pg">
          <span class="pg-info" id="pg_info">Page 1</span>
          <div class="pg-controls" id="pg_controls"></div>
        </div>
        <div class="enroll-footer">
          <span class="sel-cnt" id="sel_cnt">0 students selected</span>
          <button type="button" class="btn btn-primary" onclick="submitEnroll()"><i class="fas fa-user-plus"></i> Enroll Selected</button>
        </div>
      </div>
    </form>
  </div>
  <div class="card">
    <div class="card-title"><i class="fas fa-users-cog"></i> Enrolled Students — Manage by Class</div>
    <p class="info-note"><i class="fas fa-info-circle"></i> Click a class to expand, edit student info or remove them.</p>
    <?php if(empty($classes_arr)): ?>
    <div class="no-data"><i class="fas fa-chalkboard"></i>You have no classes yet.</div>
    <?php else: foreach($classes_arr as $cls): $enrolled=$enrollments_by_class[$cls['id']]??[]; $ecnt=count($enrolled); ?>
    <div class="cls-block">
      <div class="cls-block-head" onclick="toggleBlk(<?php echo $cls['id']; ?>)">
        <div class="cb-title"><i class="fas fa-chalkboard-teacher" style="color:var(--ap);"></i><?php echo htmlspecialchars($cls['class_name']); ?><span class="cb-cnt"><?php echo $ecnt; ?> student<?php echo $ecnt!=1?'s':''; ?></span></div>
        <div style="display:flex;align-items:center;gap:8px;" onclick="event.stopPropagation()">
          <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Delete class <?php echo htmlspecialchars(addslashes($cls['class_name'])); ?>?');" style="display:inline;">
            <input type="hidden" name="class_id" value="<?php echo $cls['id']; ?>">
            <button type="submit" name="delete_class" value="1" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
          </form>
          <i class="fas fa-chevron-down" id="blk-icon-<?php echo $cls['id']; ?>" style="color:var(--muted);font-size:.8rem;transition:.2s;"></i>
        </div>
      </div>
      <div class="cls-block-body" id="blk-<?php echo $cls['id']; ?>">
        <?php if(empty($enrolled)): ?><div class="no-data"><i class="fas fa-user-slash"></i>No students enrolled.</div>
        <?php else: foreach($enrolled as $en): ?>
        <div class="smr-row">
          <div class="smr-av"><i class="fas fa-user-graduate"></i></div>
          <div class="smr-info"><div class="smr-name"><?php echo htmlspecialchars($en['fullname']); ?></div><div class="smr-email"><?php echo htmlspecialchars($en['email']); ?></div></div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            <button type="button" class="btn btn-warn btn-sm" onclick="toggleEdit(<?php echo $en['eid']; ?>)"><i class="fas fa-pen"></i> Edit</button>
            <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($en['fullname'])); ?> from <?php echo htmlspecialchars(addslashes($cls['class_name'])); ?>?');" style="display:inline;">
              <input type="hidden" name="enrollment_id" value="<?php echo $en['eid']; ?>">
              <button type="submit" name="unenroll_student" value="1" class="btn btn-danger btn-sm"><i class="fas fa-user-minus"></i> Remove</button>
            </form>
          </div>
          <div class="smr-edit" id="edit-<?php echo $en['eid']; ?>">
            <form method="POST" action="admin_dashboard.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;width:100%;">
              <input type="hidden" name="person_id" value="<?php echo $en['pid']; ?>">
              <input type="text"  name="fullname" value="<?php echo htmlspecialchars($en['fullname']); ?>" required placeholder="Full Name">
              <input type="email" name="email"    value="<?php echo htmlspecialchars($en['email']); ?>"    required placeholder="Email">
              <button type="submit" name="update_student" value="1" class="btn btn-save btn-sm"><i class="fas fa-save"></i> Save</button>
              <button type="button" class="btn btn-sm" style="background:rgba(255,255,255,.08);color:var(--muted);" onclick="toggleEdit(<?php echo $en['eid']; ?>)">Cancel</button>
            </form>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- GRADES -->
<div class="section <?php echo $active_section=='grades'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-graduation-cap"></i> Grades Management</div>
  <?php if(empty($grades_by_class)): ?>
  <div class="card" style="text-align:center;padding:28px;color:var(--muted);"><i class="fas fa-graduation-cap" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.3;"></i>No enrollments in your classes yet.</div>
  <?php else: foreach($grades_by_class as $class_name => $rows):
    $avgs=array_filter(array_column($rows,'average')); $cavg=count($avgs)?round(array_sum($avgs)/count($avgs),1):null;
    $cclr=$cavg===null?'rgba(79,172,254,.12)':($cavg>=75?'rgba(46,204,113,.14)':'rgba(255,107,129,.14)');
    $ctxt=$cavg===null?'#4facfe':($cavg>=75?'#2ecc71':'#ff6b81');
  ?>
  <div class="grp-block">
    <div class="grp-head">
      <i class="fas fa-chalkboard-teacher" style="color:var(--ap);"></i>
      <span class="grp-name"><?php echo htmlspecialchars($class_name); ?></span>
      <span class="grp-tag" style="background:rgba(79,172,254,.15);color:var(--ap);"><?php echo count($rows); ?> student<?php echo count($rows)!=1?'s':''; ?></span>
      <?php if($cavg!==null): ?><span class="grp-tag" style="background:<?php echo $cclr; ?>;color:<?php echo $ctxt; ?>;">Avg: <?php echo $cavg; ?></span><?php endif; ?>
    </div>
    <div class="grp-body">
      <table>
        <tr><th>Student</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Average</th><th>Status</th><th>Save</th></tr>
        <?php foreach($rows as $row):
          $avg=$row['average']; $ac=$avg>=85?'avg-hi':($avg>=75?'avg-mid':'avg-lo');
          if(!$avg) $badge='<span class="badge b-blue">No Grade</span>';
          elseif($avg>=75) $badge='<span class="badge b-green"><i class="fas fa-check"></i> Passed</span>';
          else $badge='<span class="badge b-red"><i class="fas fa-times"></i> Failed</span>';
        ?>
        <tr><form method="POST" action="admin_dashboard.php">
          <input type="hidden" name="enrollment_id" value="<?php echo $row['enrollment_id']; ?>">
          <td style="font-weight:700; text-align:left;"><?php echo htmlspecialchars($row['fullname']); ?></td>
          <td><input type="number" name="prelim"  min="0" max="100" step=".01" value="<?php echo $row['prelim']?:''; ?>" placeholder="0"></td>
          <td><input type="number" name="midterm" min="0" max="100" step=".01" value="<?php echo $row['midterm']?:''; ?>" placeholder="0"></td>
          <td><input type="number" name="final"   min="0" max="100" step=".01" value="<?php echo $row['final']?:''; ?>"  placeholder="0"></td>
          <td class="<?php echo $ac; ?>"><?php echo $avg?number_format($avg,2):'—'; ?></td>
          <td><?php echo $badge; ?></td>
          <td><button type="submit" name="save_grades" value="1" class="btn btn-save btn-sm"><i class="fas fa-save"></i> Save</button></td>
        </form></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- ACTIVITIES -->
<div class="section <?php echo $active_section=='activities'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-tasks"></i> Activity Management</div>
  <div class="card">
    <div class="card-title"><i class="fas fa-paper-plane"></i> Assign Activity to Entire Class</div>
    <p class="info-note"><i class="fas fa-info-circle"></i> Select one of your classes — activity is assigned to <strong>all enrolled students</strong> automatically.</p>
    <form method="POST" action="admin_dashboard.php">
      <div class="form-grid">
        <div class="field"><label>Class *</label>
          <select name="class_id" required><option value="">-- Select Class --</option>
          <?php foreach($classes_arr as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['class_name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Activity Name *</label><input type="text" name="activity_name" placeholder="e.g. Quiz 1, Lab Report 2..." required></div>
        <div class="field" style="display:flex;align-items:flex-end;"><button type="submit" name="add_activity" value="1" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fas fa-paper-plane"></i> Assign to All</button></div>
      </div>
    </form>
  </div>
  <?php
  $acts_by_class=[];
  while($row=$result_activities->fetch_assoc()){
    $acts_by_class[$row['class_name']][$row['activity_name']][]=$row;
  }
  ?>
  <?php if(empty($acts_by_class)): ?>
  <div class="card" style="text-align:center;padding:28px;color:var(--muted);"><i class="fas fa-tasks" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.3;"></i>No activities assigned in your classes yet.</div>
  <?php else: foreach($acts_by_class as $cls_name => $acts_grouped):
    $all_rows=array_merge(...array_values($acts_grouped));
    $done_c=count(array_filter($all_rows,fn($a)=>$a['completed']));
    $pend_c=count($all_rows)-$done_c;
    $act_count=count($acts_grouped);
  ?>
  <div class="act-class-block">
    <div class="act-class-head">
      <div class="act-class-icon"><i class="fas fa-chalkboard-teacher"></i></div>
      <span class="act-class-name"><?php echo htmlspecialchars($cls_name); ?></span>
      <span class="act-tag" style="background:rgba(79,172,254,.15);color:var(--ap);"><?php echo $act_count; ?> activit<?php echo $act_count!=1?'ies':'y'; ?></span>
      <span class="act-tag act-tag-done"><i class="fas fa-check"></i> <?php echo $done_c; ?> done</span>
      <span class="act-tag act-tag-pend"><i class="fas fa-clock"></i> <?php echo $pend_c; ?> pending</span>
    </div>
    <div class="act-sub-wrap">
      <?php foreach($acts_grouped as $act_name => $students):
        $adone=count(array_filter($students,fn($s)=>$s['completed']));
        $apend=count($students)-$adone;
      ?>
      <div class="act-sub">
        <div class="act-sub-head">
          <i class="fas fa-file-alt" style="color:var(--ap);font-size:.82rem;"></i>
          <span class="act-sub-name"><?php echo htmlspecialchars($act_name); ?></span>
          <span class="act-sub-tag" style="background:rgba(79,172,254,.14);color:var(--ap);"><?php echo count($students); ?> student<?php echo count($students)!=1?'s':''; ?></span>
          <span class="act-sub-tag" style="background:rgba(46,204,113,.14);color:#2ecc71;"><?php echo $adone; ?> done</span>
          <span class="act-sub-tag" style="background:rgba(255,211,42,.14);color:#e6b800;"><?php echo $apend; ?> pending</span>
          <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Remove &quot;<?php echo htmlspecialchars(addslashes($act_name)); ?>&quot; from ALL students?');" style="margin-left:auto;flex-shrink:0;">
            <input type="hidden" name="class_id"      value="<?php echo $students[0]['class_id']; ?>">
            <input type="hidden" name="activity_name" value="<?php echo htmlspecialchars($act_name); ?>">
            <button type="submit" name="delete_class_activity" value="1" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Remove All</button>
          </form>
        </div>
        <div class="act-list">
          <?php foreach($students as $row):
            $is_done=(bool)$row['completed'];
            $row_cls='act-row '.($is_done?'done':'pending');
          ?>
          <div class="<?php echo $row_cls; ?>">
            <div class="act-row-left">
              <span class="act-dot"></span>
              <span class="act-student-name"><?php echo htmlspecialchars($row['fullname']); ?></span>
            </div>
            <?php if($is_done): ?>
              <span class="act-status-pill pill-done"><i class="fas fa-check-circle"></i> Completed</span>
            <?php else: ?>
              <span class="act-status-pill pill-pend"><i class="fas fa-hourglass-half"></i> Pending</span>
            <?php endif; ?>
            <div class="act-row-actions">
              <form method="POST" action="admin_dashboard.php" style="display:inline;">
                <input type="hidden" name="activity_id"     value="<?php echo $row['activity_id']; ?>">
                <input type="hidden" name="update_activity" value="1">
                <label class="toggle" style="font-size:.78rem;"><input type="checkbox" name="completed" <?php echo $row['completed']?'checked':''; ?> onChange="this.form.submit()"> Done</label>
              </form>
              <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Delete for <?php echo htmlspecialchars(addslashes($row['fullname'])); ?> only?');" style="display:inline;">
                <input type="hidden" name="activity_id" value="<?php echo $row['activity_id']; ?>">
                <button type="submit" name="delete_activity" value="1" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- REMINDERS -->
<div class="section <?php echo $active_section=='reminders'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-bell"></i> My Reminders</div>
  <div class="card">
    <div class="card-title"><i class="fas fa-plus-circle"></i> Post New Reminder</div>
    <form method="POST" action="admin_dashboard.php">
      <div class="form-grid">
        <div class="field fg-full"><label>Title *</label><input type="text" name="reminder_title" placeholder="e.g. Midterm Exam on Friday" required></div>
        <div class="field fg-full"><label>Message Body</label><textarea name="reminder_body" placeholder="Describe the reminder..."></textarea></div>
        <!-- Target audience is always students — hidden field -->
        <input type="hidden" name="target_role" value="user">
        <?php if($has_class_id): ?>
        <div class="field"><label>Target Class (optional)</label>
          <select name="reminder_class_id">
            <option value="">— All My Students —</option>
            <?php foreach($classes_arr as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['class_name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="field"><label>Due Date (optional)</label><input type="date" name="due_date"></div>
        <div class="field" style="display:flex;align-items:flex-end;"><button type="submit" name="add_reminder" value="1" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fas fa-paper-plane"></i> Post</button></div>
      </div>
    </form>
  </div>
  <?php
  $rems_global=[]; $rems_by_class=[];
  while($row=$result_reminders->fetch_assoc()){
    if(!empty($row['class_id'])) $rems_by_class[$row['cls_name']??'Unknown'][]=$row;
    else $rems_global[]=$row;
  }
  ?>
  <?php if(!empty($rems_global)): ?>
  <div class="grp-block">
    <div class="grp-head green">
      <i class="fas fa-users" style="color:#2ecc71;"></i>
      <span class="grp-name green">General — All Students</span>
      <span class="grp-tag" style="background:rgba(46,204,113,.14);color:#2ecc71;"><?php echo count($rems_global); ?> reminder<?php echo count($rems_global)!=1?'s':''; ?></span>
    </div>
    <div style="padding:10px 14px;">
    <?php foreach($rems_global as $row): $tl=$row['target_role']==='user'?'Students':($row['target_role']==='admin'?'Admins':'Everyone'); ?>
    <div class="ri glb-ri">
      <div style="flex:1;">
        <div class="ri-title"><i class="fas fa-bell" style="color:#2ecc71;"></i><?php echo htmlspecialchars($row['title']); ?><span class="all-tag"><i class="fas fa-users" style="margin-right:3px;"></i><?php echo $tl; ?></span></div>
        <?php if(!empty($row['body'])): ?><div class="ri-text"><?php echo nl2br(htmlspecialchars($row['body'])); ?></div><?php endif; ?>
        <div class="ri-meta">
          <?php if($row['due_date']): ?><span><i class="fas fa-calendar-alt"></i> Due: <?php echo date('M j,Y',strtotime($row['due_date'])); ?></span><?php endif; ?>
          <?php if(!empty($row['created_at'])): ?><span><i class="fas fa-clock"></i> <?php echo date('M j,Y g:i A',strtotime($row['created_at'])); ?></span><?php endif; ?>
        </div>
      </div>
      <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Delete?');" style="flex-shrink:0;">
        <input type="hidden" name="reminder_id" value="<?php echo $row['id']; ?>">
        <button type="submit" name="delete_reminder" value="1" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
      </form>
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
    <?php foreach($rems as $row): ?>
    <div class="ri cls-ri">
      <div style="flex:1;">
        <div class="ri-title"><i class="fas fa-bell" style="color:var(--ap);"></i><?php echo htmlspecialchars($row['title']); ?><span class="cls-tag"><i class="fas fa-chalkboard" style="margin-right:3px;"></i><?php echo htmlspecialchars($cls_name); ?></span></div>
        <?php if(!empty($row['body'])): ?><div class="ri-text"><?php echo nl2br(htmlspecialchars($row['body'])); ?></div><?php endif; ?>
        <div class="ri-meta">
          <?php if($row['due_date']): ?><span><i class="fas fa-calendar-alt"></i> Due: <?php echo date('M j,Y',strtotime($row['due_date'])); ?></span><?php endif; ?>
          <?php if(!empty($row['created_at'])): ?><span><i class="fas fa-clock"></i> <?php echo date('M j,Y g:i A',strtotime($row['created_at'])); ?></span><?php endif; ?>
        </div>
      </div>
      <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Delete?');" style="flex-shrink:0;">
        <input type="hidden" name="reminder_id" value="<?php echo $row['id']; ?>">
        <button type="submit" name="delete_reminder" value="1" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
      </form>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($rems_global)&&empty($rems_by_class)): ?>
  <div class="card" style="text-align:center;padding:28px;color:var(--muted);"><i class="fas fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.3;"></i>You have no reminders yet.</div>
  <?php endif; ?>
</div>

<!-- PERFORMANCE -->
<div class="section <?php echo $active_section=='performance'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-chart-line"></i> My Students' Performance</div>
  <div class="card"><table>
    <tr><th>Student</th><th>Classes</th><th>GPA</th><th>Passed</th><th>Failed</th><th>Acts Done</th><th>Act Rate</th><th>Grade Bar</th><th>Overall</th></tr>
    <?php while($row=$perf_result->fetch_assoc()):
      $gpa=floatval($row['gpa']??0); $at=intval($row['acts_total']??0); $ad=intval($row['acts_done']??0);
      $ap2=$at>0?round(($ad/$at)*100):0; $gp=min(100,round($gpa)); $ov=round($gp*.7+$ap2*.3);
      $gc=$gpa>=85?'avg-hi':($gpa>=75?'avg-mid':'avg-lo');
      $ob=$ov>=80?'b-green':($ov>=60?'b-gold':'b-red'); $bc=$gpa>=75?'pbar-green':'pbar-red';
    ?>
    <tr>
      <td style="text-align:left;font-weight:700;"><?php echo htmlspecialchars($row['fullname']); ?><br><span style="font-size:.7rem;color:var(--muted);font-weight:400;"><?php echo htmlspecialchars($row['email']); ?></span></td>
      <td><?php echo $row['classes_count']; ?></td>
      <td class="<?php echo $gc; ?>"><?php echo $gpa?number_format($gpa,1):'—'; ?></td>
      <td><span class="badge b-green"><?php echo $row['passed_count']??0; ?></span></td>
      <td><span class="badge b-red"><?php echo $row['failed_count']??0; ?></span></td>
      <td><?php echo $ad; ?>/<?php echo $at; ?></td>
      <td style="min-width:90px;"><?php echo $ap2; ?>%<div class="pbar pbar-blue"><div class="pbar-inner" style="width:<?php echo $ap2; ?>%;"></div></div></td>
      <td style="min-width:90px;"><?php echo $gp; ?>%<div class="pbar <?php echo $bc; ?>"><div class="pbar-inner" style="width:<?php echo $gp; ?>%;"></div></div></td>
      <td><span class="badge <?php echo $ob; ?> perf-score"><?php echo $ov; ?>%</span></td>
    </tr>
    <?php endwhile; ?>
  </table></div>
</div>

<!-- ANALYTICS -->
<div class="section <?php echo $active_section=='analytics'?'active':''; ?>">
  <div class="sec-title"><i class="fas fa-chart-bar"></i> My Analytics</div>
  <div class="chart-grid">
    <div class="chart-card"><h3><i class="fas fa-chart-bar"></i> Class Average Grades</h3><canvas id="barB" height="220"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-chart-pie"></i> Pass vs Fail</h3><canvas id="doB" height="220"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-tasks"></i> Activity Completion</h3><canvas id="actB" height="220"></canvas></div>
    <div class="chart-card"><h3><i class="fas fa-user-graduate"></i> Enrollments per Class</h3><canvas id="enrB" height="220"></canvas></div>
  </div>
</div>

</div></div><!-- end main / layout -->

<!-- MODAL -->
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
particlesJS('particles-js',{particles:{number:{value:70,density:{enable:true,value_area:900}},color:{value:"#4facfe"},shape:{type:"circle"},opacity:{value:0.25,random:true},size:{value:2.2,random:true},line_linked:{enable:true,distance:130,color:"#00c6ff",opacity:0.13,width:1},move:{enable:true,speed:1.3,direction:"none",random:true}},interactivity:{detect_on:"canvas",events:{onhover:{enable:true,mode:"grab"},onclick:{enable:true,mode:"push"}},modes:{grab:{distance:130,line_linked:{opacity:0.4}},push:{particles_nb:3}}},retina_detect:true});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const R=document.documentElement;
function isDark(){return R.getAttribute('data-theme')!=='light';}
function chartGrid(){return isDark()?'rgba(0,255,255,0.05)':'rgba(79,100,220,0.10)';}
function chartLabel(){return isDark()?'rgba(0,210,255,0.60)':'rgba(50,70,180,0.65)';}
function applyTheme(t){
  R.setAttribute('data-theme',t);localStorage.setItem('paops_theme',t);
  document.getElementById('btn-dark').classList.toggle('on',t==='dark');
  document.getElementById('btn-light').classList.toggle('on',t==='light');
}
function setTheme(t){applyTheme(t);}
function applyAccent(c){R.style.setProperty('--ap',c);localStorage.setItem('paops_accent',c);document.querySelectorAll('.accent-dot').forEach(d=>d.classList.toggle('on',d.dataset.c===c));}
function setAccent(c){applyAccent(c);}
function openModal(){document.getElementById('themeModal').classList.add('open');}
function closeModal(){document.getElementById('themeModal').classList.remove('open');}
(function(){
  applyTheme(localStorage.getItem('paops_theme')||'dark');
  applyAccent(localStorage.getItem('paops_accent')||'#4facfe');
})();
Chart.defaults.color=chartLabel();Chart.defaults.borderColor=chartGrid();
const CL=<?php echo json_encode($chart_classes); ?>;
const CA=<?php echo json_encode($chart_avgs); ?>;
const ED=<?php echo json_encode($enroll_per_class); ?>;
const passed=<?php echo intval($passed); ?>,failed=<?php echo intval($failed); ?>;
const compA=<?php echo intval($comp_acts); ?>,pendA=<?php echo intval($pend_acts); ?>;
function mkBar(id,l,d,c){const el=document.getElementById(id);if(!el)return;const gc=chartGrid(),lc=chartLabel();new Chart(el,{type:'bar',data:{labels:l,datasets:[{data:d,backgroundColor:c+'55',borderColor:c,borderWidth:2,borderRadius:5}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:gc},ticks:{color:lc}},x:{grid:{color:gc},ticks:{color:lc}}}}});}
function mkDonut(id,l,d,cols){const el=document.getElementById(id);if(!el)return;new Chart(el,{type:'doughnut',data:{labels:l,datasets:[{data:d,backgroundColor:cols.map(x=>x+'aa'),borderColor:cols,borderWidth:2}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{padding:12,boxWidth:11,color:chartLabel()}}},cutout:'60%'}});}
mkBar('barA',CL,CA,'#4facfe');mkBar('barB',CL,CA,'#4facfe');
mkBar('actA',['Completed','Pending'],[compA,pendA],'#00cec9');mkBar('actB',['Completed','Pending'],[compA,pendA],'#00cec9');
mkBar('enrB',CL,ED,'#a29bfe');
mkDonut('doA',['Passed','Failed'],[passed,failed],['#2ecc71','#ff6b81']);
mkDonut('doB',['Passed','Failed'],[passed,failed],['#2ecc71','#ff6b81']);
/* ENROLL PAGINATION */
const PER_PAGE=6;let enrollPage=1,enrollQuery='';
function getRows(){return Array.from(document.querySelectorAll('#chk_list .chk-row'));}
function getVisible(){const q=enrollQuery.toLowerCase();return getRows().filter(r=>!q||(r.dataset.name||'').includes(q));}
function renderPage(page){
  enrollPage=page;const visible=getVisible();const total=visible.length;
  const pages=Math.max(1,Math.ceil(total/PER_PAGE));
  if(enrollPage>pages)enrollPage=pages;
  const start=(enrollPage-1)*PER_PAGE,end=start+PER_PAGE;
  getRows().forEach(r=>r.classList.add('pg-hidden'));
  visible.forEach((r,i)=>{if(i>=start&&i<end)r.classList.remove('pg-hidden');});
  const info=document.getElementById('pg_info');
  if(info)info.textContent=total===0?'No students found':`Page ${enrollPage} of ${pages} — ${total} student${total!==1?'s':''} shown`;
  buildPager(pages);
}
function buildPager(pages){
  const ctrl=document.getElementById('pg_controls');if(!ctrl)return;ctrl.innerHTML='';
  const btn=(label,page,disabled,active)=>{const b=document.createElement('button');b.type='button';b.className='pg-btn'+(active?' active':'');b.innerHTML=label;b.disabled=disabled;if(!disabled)b.onclick=()=>renderPage(page);return b;};
  ctrl.appendChild(btn('<i class="fas fa-chevron-left"></i>',enrollPage-1,enrollPage===1,false));
  let nums=[];
  if(pages<=7){for(let i=1;i<=pages;i++)nums.push(i);}
  else{nums=[1];if(enrollPage>3){const d=document.createElement('span');d.className='pg-dots';d.textContent='…';nums.push(d);}for(let i=Math.max(2,enrollPage-1);i<=Math.min(pages-1,enrollPage+1);i++)nums.push(i);if(enrollPage<pages-2){const d=document.createElement('span');d.className='pg-dots';d.textContent='…';nums.push(d);}nums.push(pages);}
  nums.forEach(n=>{if(typeof n==='number')ctrl.appendChild(btn(n,n,false,n===enrollPage));else ctrl.appendChild(n);});
  ctrl.appendChild(btn('<i class="fas fa-chevron-right"></i>',enrollPage+1,enrollPage===pages||pages===0,false));
}
function toggleChk(row){const cb=row.querySelector('input[type="checkbox"]');const on=!row.classList.contains('checked');row.classList.toggle('checked',on);cb.checked=on;updateCnt();}
function updateCnt(){const n=document.querySelectorAll('.chk-row.checked').length;const el=document.getElementById('sel_cnt');if(el)el.textContent=n+' student'+(n===1?'':'s')+' selected';}
function pickAll(v){getRows().forEach(r=>{r.classList.toggle('checked',v);const cb=r.querySelector('input[type="checkbox"]');if(cb)cb.checked=v;});updateCnt();}
function enrollSearch(q){enrollQuery=q;renderPage(1);}
document.addEventListener('DOMContentLoaded',()=>renderPage(1));
function toggleBlk(id){const b=document.getElementById('blk-'+id);const ic=document.getElementById('blk-icon-'+id);const op=b.classList.contains('open');b.classList.toggle('open',!op);ic.style.transform=op?'':'rotate(180deg)';}
function toggleEdit(eid){document.getElementById('edit-'+eid).classList.toggle('open');}
// Remove flash params from URL immediately so refreshing won't re-show the message
(function(){
  const url = new URL(window.location.href);
  if(url.searchParams.has('flash') || url.searchParams.has('ft')){
    url.searchParams.delete('flash');
    url.searchParams.delete('ft');
    window.history.replaceState(null, '', url.toString());
  }
})();

// Auto-fade the flash message after 4.5 s
setTimeout(()=>{const f=document.querySelector('.flash');if(f){f.style.transition='opacity .5s';f.style.opacity='0';}},4500);

function showResult(el, isError, msg){
  // Show as a top-of-page flash, same as PHP flash messages
  let toast = document.getElementById('enroll-toast');
  if(!toast){
    toast = document.createElement('div');
    toast.id = 'enroll-toast';
    // Insert before the first section div inside .main
    const main = document.querySelector('.main');
    const firstSection = main.querySelector('.section');
    main.insertBefore(toast, firstSection);
  }
  toast.className = 'flash ' + (isError ? 'error' : 'success');
  toast.innerHTML = '<i class="fas ' + (isError ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i> ' + msg;
  toast.style.display = 'flex';
  // Scroll to top so user sees it
  document.querySelector('.main').scrollTo({top: 0, behavior: 'smooth'});
  clearTimeout(toast._hideTimer);
  toast._hideTimer = setTimeout(() => {
    toast.style.transition = 'opacity .5s';
    toast.style.opacity = '0';
    setTimeout(() => {
      toast.style.display = 'none';
      toast.style.opacity = '1';
    }, 500);
  }, 4000);
}

function buildStudentCard(s){
  /* Mirrors the .smr-row PHP markup so the card looks identical */
  return `
  <div class="smr-row" id="smr-eid-${s.eid}">
    <div class="smr-av"><i class="fas fa-user-graduate"></i></div>
    <div class="smr-info">
      <div class="smr-name">${s.fullname}</div>
      <div class="smr-email">${s.email}</div>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0;">
      <button type="button" class="btn btn-warn btn-sm" onclick="toggleEdit(${s.eid})"><i class="fas fa-pen"></i> Edit</button>
      <form method="POST" action="admin_dashboard.php"
            onsubmit="return confirm('Remove ${s.fullname.replace(/'/g,"\\'")} from this class?');" style="display:inline;">
        <input type="hidden" name="enrollment_id" value="${s.eid}">
        <button type="submit" name="unenroll_student" value="1" class="btn btn-danger btn-sm"><i class="fas fa-user-minus"></i> Remove</button>
      </form>
    </div>
    <div class="smr-edit" id="edit-${s.eid}">
      <form method="POST" action="admin_dashboard.php"
            style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;width:100%;">
        <input type="hidden" name="person_id" value="${s.pid}">
        <input type="text"  name="fullname" value="${s.fullname}" required placeholder="Full Name">
        <input type="email" name="email"    value="${s.email}"    required placeholder="Email">
        <button type="submit" name="update_student" value="1" class="btn btn-save btn-sm"><i class="fas fa-save"></i> Save</button>
        <button type="button" class="btn btn-sm"
                style="background:rgba(255,255,255,.08);color:var(--muted);"
                onclick="toggleEdit(${s.eid})">Cancel</button>
      </form>
    </div>
  </div>`;
}

function injectStudents(classId, students){
  const body = document.getElementById('blk-' + classId);
  if(!body) return;

  // Remove "no students" placeholder if present
  const noData = body.querySelector('.no-data');
  if(noData) noData.remove();

  students.forEach(s => {
    // Skip if already present (shouldn't happen but be safe)
    if(document.getElementById('smr-eid-' + s.eid)) return;
    body.insertAdjacentHTML('beforeend', buildStudentCard(s));
  });

  // Update the count badge in the header
  const badge = document.querySelector(`#blk-icon-${classId}`)
                  ?.closest('.cls-block-head')
                  ?.querySelector('.cb-cnt');
  if(badge){
    const current = body.querySelectorAll('.smr-row').length;
    badge.textContent = current + ' student' + (current !== 1 ? 's' : '');
  }

  // Auto-expand the class block so the user sees the new student immediately
  if(!body.classList.contains('open')){
    body.classList.add('open');
    const ic = document.getElementById('blk-icon-' + classId);
    if(ic) ic.style.transform = 'rotate(180deg)';
  }
}

function clearAllChecked(){
  document.querySelectorAll('#chk_list .chk-row.checked').forEach(r => {
    r.classList.remove('checked');
    const cb = r.querySelector('input[type="checkbox"]');
    if(cb) cb.checked = false;
  });
  updateCnt();
}

function submitEnroll(){
  const form        = document.getElementById('enrollForm');
  const classId     = form.querySelector('select[name="class_id"]').value;
  const checkedRows = document.querySelectorAll('#chk_list .chk-row.checked');

  if(!classId){
    showResult(null, true, 'Please select a class.'); return;
  }
  if(checkedRows.length === 0){
    showResult(null, true, 'Please select at least one student.'); return;
  }

  // Show loading indicator as top flash
  const toast = document.getElementById('enroll-toast') || (() => {
    const t = document.createElement('div');
    t.id = 'enroll-toast';
    const main = document.querySelector('.main');
    main.insertBefore(t, main.querySelector('.section'));
    return t;
  })();
  toast.className   = 'flash';
  toast.style.cssText = 'display:flex;background:rgba(79,172,254,.10);border:1px solid rgba(79,172,254,.25);color:#4facfe;';
  toast.innerHTML   = '<i class="fas fa-spinner fa-spin"></i> &nbsp;Enrolling…';
  document.querySelector('.main').scrollTo({top:0, behavior:'smooth'});

  const data = new FormData();
  data.append('class_id', classId);
  data.append('enroll_student', '1');
  data.append('ajax', '1');
  checkedRows.forEach(row => {
    const cb = row.querySelector('input[name="student_ids[]"]');
    if(cb) data.append('student_ids[]', cb.value);
  });

  fetch('admin_dashboard.php', {method:'POST', body:data})
    .then(r => r.json())
    .then(json => {
      clearAllChecked();
      form.querySelector('select[name="class_id"]').value = '';
      if(json.ok && json.enrolled && json.enrolled.length > 0){
        injectStudents(json.class_id, json.enrolled);
      }
      showResult(null, !json.ok, json.msg);
    })
    .catch(() => {
      clearAllChecked();
      showResult(null, true, 'Network error. Please try again.');
    });
}
</script>
</body>
</html>