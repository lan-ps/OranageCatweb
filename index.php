<?php
/**
 * 系统主页面 - 转发记录列表
 * 
 * 功能说明:
 * - 显示短信/来电转发记录列表（支持分页、筛选、增量拉取）
 * - 支持时间筛选（今天、最近7天、全部）
 * - 支持设备筛选
 * - 支持查看记录详情
 * - 支持个人资料修改（用户名、密码）
 * 
 * 访问方式: GET /index.php
 * 
 * 认证要求:
 * - 必须登录（通过 requireWebLogin() 强制跳转登录页）
 * 
 * 页面特性:
 * - 响应式设计，适配移动端和桌面端
 * - 增量更新：新记录自动插入列表顶部，带"挤下"动画
 * - ETag/304 缓存：无新数据时零流量响应
 * - 星空粒子背景 + 光晕动画
 * - 鼠标水波纹涟漪效果
 * 
 * 依赖文件:
 * - config.php: 全局配置和公共函数
 * - api/list.php: 获取记录列表
 * - api/detail.php: 获取记录详情
 * - api/profile.php: 修改个人资料
 */
define('APP_STARTED', true);
require_once __DIR__ . '/config.php';
requireWebLogin();
$currentUser = currentUser();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#eef2f6">
<title>橘猫客户端-来电来信转发系统</title>
<link rel="icon" href="favicon.ico" />
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
    background:linear-gradient(160deg,#e8ecf1 0%,#eef2f6 40%,#f1f5f9 100%);
    color:#1e293b;
    min-height:100vh;min-height:100dvh;
    padding:0;
    padding-top:max(0px,env(safe-area-inset-top));
    padding-bottom:max(0px,env(safe-area-inset-bottom));
    position:relative;
}

/* 光晕 */
.glow-orb{position:fixed;border-radius:50%;pointer-events:none;filter:blur(100px);z-index:0}
.glow-orb.glow-1{
    width:500px;height:500px;
    background:radial-gradient(circle,rgba(59,130,246,0.1) 0%,transparent 70%);
    top:-150px;right:-100px;animation:float1 12s ease-in-out infinite;
}
.glow-orb.glow-2{
    width:400px;height:400px;
    background:radial-gradient(circle,rgba(14,165,233,0.07) 0%,transparent 70%);
    bottom:-80px;left:-80px;animation:float2 14s ease-in-out infinite reverse;
}
.glow-orb.glow-3{
    width:350px;height:350px;
    background:radial-gradient(circle,rgba(245,158,11,0.05) 0%,transparent 70%);
    top:40%;left:50%;transform:translate(-50%,-50%);
    animation:pulse 8s ease-in-out infinite;
}
@keyframes float1{
    0%,100%{transform:translate(0,0) scale(1)}
    50%{transform:translate(-30px,40px) scale(1.12)}
}
@keyframes float2{
    0%,100%{transform:translate(0,0) scale(1)}
    50%{transform:translate(40px,-20px) scale(1.1)}
}
@keyframes pulse{
    0%,100%{transform:translate(-50%,-50%) scale(1);opacity:0.5}
    50%{transform:translate(-50%,-50%) scale(1.25);opacity:1}
}

/* 星空粒子 */
.stars{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.star{
    position:absolute;border-radius:50%;
    background:rgba(59,130,246,0.35);
    animation:twinkle var(--d) ease-in-out infinite;
    animation-delay:var(--delay);
    opacity:0;
}
@keyframes twinkle{
    0%,100%{opacity:0;transform:scale(0.6)}
    50%{opacity:var(--o);transform:scale(1)}
}

/* 导航栏 */
.navbar{
    position:sticky;top:0;z-index:100;
    background:rgba(255,255,255,0.78);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
    border-bottom:1px solid rgba(0,0,0,0.06);
    height:60px;
    box-shadow:0 1px 0 rgba(0,0,0,0.03);
}
.navbar-inner{
    max-width:1280px;margin:0 auto;
    padding:0 28px;
    display:flex;align-items:center;justify-content:space-between;
    height:100%;
}
.navbar .brand{
    display:flex;align-items:center;gap:10px;
    font-size:17px;font-weight:700;color:#0f172a;
    text-decoration:none;letter-spacing:0.3px;
}
.navbar .brand .brand-logo{width:34px;height:34px;border-radius:9px}
.navbar .brand .brand-title{color:#0f172a}
.navbar .nav-actions{display:flex;align-items:center;gap:12px}
.navbar .user-chip{
    display:flex;align-items:center;gap:7px;
    background:#f1f5f9;border-radius:20px;
    padding:5px 12px 5px 5px;font-size:12px;color:#475569;
    transition:all 0.2s;
}
.navbar .user-chip:hover{background:#e2e8f0;transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,0.06)}
.navbar .user-chip .avatar{
    width:26px;height:26px;border-radius:50%;
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:700;
}
.navbar .user-chip .name{font-weight:500}
.navbar .nav-icon-btn{
    display:inline-flex;align-items:center;justify-content:center;
    background:transparent;border:1.5px solid #e2e8f0;
    color:#64748b;padding:6px 14px;border-radius:8px;
    font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;
    transition:all 0.2s;
    -webkit-tap-highlight-color:transparent;
}
.navbar .nav-icon-btn:hover{background:#f1f5f9;border-color:#cbd5e1;color:#0f172a}
.navbar .nav-icon-btn:active{transform:scale(0.95)}
.navbar .nav-icon-btn.logout:hover{background:#fef2f2;border-color:#fecaca;color:#dc2626}
.navbar .nav-icon-btn.refresh.spinning{color:#3b82f6;border-color:#93c5fd;background:#eff6ff;pointer-events:none}


/* 主容器 */
.container{max-width:1280px;margin:0 auto;padding:24px 28px;position:relative;z-index:1}
@media(max-width:768px){.container{padding:16px}}

/* 时间筛选卡 */
.stats-bar{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:14px;margin-bottom:20px;
}
.stats-bar .stat-card{
    background:rgba(255,255,255,0.85);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    border:1.5px solid rgba(0,0,0,0.06);
    border-radius:14px;padding:18px 20px;
    text-align:center;cursor:pointer;
    transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
    box-shadow:0 1px 3px rgba(0,0,0,0.04);
    position:relative;overflow:hidden;
}
.stats-bar .stat-card::before{
    content:'';position:absolute;bottom:0;left:20%;right:20%;height:3px;
    border-radius:3px 3px 0 0;
    background:linear-gradient(90deg,#3b82f6,#0ea5e9);
    transform:scaleX(0);transition:transform 0.3s ease;
}
.stats-bar .stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,0.06)}
.stats-bar .stat-card.active{
    border-color:rgba(59,130,246,0.25);
    background:rgba(255,255,255,0.95);
    box-shadow:0 0 0 3px rgba(59,130,246,0.08),0 4px 16px rgba(0,0,0,0.06);
}
.stats-bar .stat-card.active::before{transform:scaleX(1)}
.stats-bar .stat-card .stat-inner .stat-label{
    font-size:14px;font-weight:600;color:#475569;
    transition:color 0.3s;
}
.stats-bar .stat-card.active .stat-inner .stat-label{color:#3b82f6}

/* 工具栏 */
.toolbar{
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:10px;
    padding:12px 16px;margin-bottom:16px;
    background:rgba(255,255,255,0.7);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    border:1px solid rgba(0,0,0,0.05);
    border-radius:12px;font-size:12px;color:#64748b;
}
.toolbar .left{display:flex;align-items:center;gap:6px}
.toolbar .pulse{
    width:7px;height:7px;border-radius:50%;
    background:#10b981;display:inline-block;
    animation:pulseDot 2s ease-in-out infinite;
}
@keyframes pulseDot{
    0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(16,185,129,0.4)}
    50%{opacity:0.6;box-shadow:0 0 0 6px rgba(16,185,129,0)}
}
.toolbar .new-badge{
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    color:#fff;padding:5px 14px;border-radius:20px;
    font-size:11px;font-weight:600;cursor:pointer;
    display:none;transition:all 0.2s;
    box-shadow:0 2px 8px rgba(59,130,246,0.25);
}
.toolbar .new-badge.show{display:inline-block}
.toolbar .new-badge:hover{transform:scale(1.05);box-shadow:0 4px 14px rgba(59,130,246,0.35)}

/* 记录列表 */
.record-list{display:flex;flex-direction:column;gap:10px}
.record-card{
    background:rgba(255,255,255,0.85);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    border:1px solid rgba(0,0,0,0.06);
    border-radius:14px;padding:16px 18px;
    display:flex;align-items:center;justify-content:space-between;
    cursor:pointer;
    transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
    box-shadow:0 1px 3px rgba(0,0,0,0.03);
    animation:cardIn 0.4s cubic-bezier(0.23,1,0.32,1) both;
    position:relative;overflow:hidden;
}
.record-card::after{
    content:'';position:absolute;left:0;top:0;bottom:0;width:3px;
    background:linear-gradient(180deg,#3b82f6,#0ea5e9);
    border-radius:0 3px 3px 0;transform:scaleY(0);
    transition:transform 0.3s ease;
}
.record-card:hover{
    transform:translateY(-2px);
    box-shadow:0 0 0 1px rgba(59,130,246,0.08),0 6px 20px rgba(0,0,0,0.06);
}
.record-card:hover::after{transform:scaleY(1)}
.record-card:active{transform:scale(0.985)}
.record-card .record-body{flex:1;min-width:0}
.record-card .row1{display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap}
.record-card .type-icon{
    width:32px;height:32px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;flex-shrink:0;
}
.record-card .type-icon.sms{background:rgba(59,130,246,0.1);color:#3b82f6}
.record-card .type-icon.call{background:rgba(16,185,129,0.1);color:#10b981}
.record-card .phone{font-size:15px;font-weight:600;color:#0f172a;text-decoration:none}
.record-card .phone.phone-link{color:#3b82f6;cursor:pointer}
.record-card .phone.phone-link:hover{text-decoration:underline}
.record-card .type-label{
    font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;
}
.record-card .type-label.sms{background:#eff6ff;color:#2563eb}
.record-card .type-label.call{background:#ecfdf5;color:#059669}
.record-card .content-text{
    font-size:13px;color:#64748b;margin-bottom:6px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.record-card .meta-row{display:flex;gap:12px;font-size:11px;color:#94a3b8;flex-wrap:wrap}
.record-card .chevron{font-size:20px;color:#cbd5e1;flex-shrink:0;margin-left:8px}

/* 增量插入动画 */
.record-card.prepend-new{animation:slideDown 0.45s cubic-bezier(.2,.7,.2,1) both}
.record-card.prepend-flash{background:rgba(219,234,254,0.6)}
@keyframes slideDown{
    from{opacity:0;transform:translateY(-20px)}
    to{opacity:1;transform:translateY(0)}
}
@keyframes cardIn{
    from{opacity:0;transform:translateY(16px)}
    to{opacity:1;transform:translateY(0)}
}

/* 分页 */
.pagination{margin-top:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.pagination .page-btns{display:flex;gap:6px}
.pagination .page-btns button{
    min-width:36px;height:36px;border-radius:10px;
    border:1px solid #e2e8f0;background:rgba(255,255,255,0.8);
    color:#475569;font-size:13px;font-weight:600;cursor:pointer;
    transition:all 0.2s;font-family:inherit;
}
.pagination .page-btns button:hover:not(:disabled){background:#f1f5f9;border-color:#cbd5e1}
.pagination .page-btns button.active{
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    color:#fff;border-color:transparent;
    box-shadow:0 2px 8px rgba(59,130,246,0.25);
}
.pagination .page-btns button:disabled{opacity:0.35;cursor:not-allowed}
.pagination .page-info{font-size:12px;color:#94a3b8}

/* 弹窗 */
.modal-mask{
    position:fixed;inset:0;z-index:200;
    background:rgba(15,23,42,0.35);
    backdrop-filter:blur(4px);
    display:none;align-items:center;justify-content:center;
    padding:20px;
}
.modal-mask.open{display:flex}
.modal{
    background:rgba(255,255,255,0.95);
    backdrop-filter:blur(20px);
    border:1px solid rgba(0,0,0,0.06);
    border-radius:20px;padding:28px 32px;
    width:100%;max-width:460px;
    box-shadow:0 0 0 1px rgba(0,0,0,0.04),0 20px 60px rgba(0,0,0,0.12);
    animation:modalIn 0.3s cubic-bezier(0.23,1,0.32,1);
}
@keyframes modalIn{
    from{opacity:0;transform:translateY(28px) scale(0.95)}
    to{opacity:1;transform:translateY(0) scale(1)}
}
.modal-head{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.modal-head .type-icon{
    width:44px;height:44px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.modal-head .type-icon.sms{background:rgba(59,130,246,0.1);color:#3b82f6}
.modal-head .type-icon.call{background:rgba(16,185,129,0.1);color:#10b981}
.modal-head .title{font-size:17px;font-weight:700;color:#0f172a}
.modal-head .sub{font-size:11px;color:#94a3b8;letter-spacing:0.5px}
.modal-close{
    margin-left:auto;width:32px;height:32px;border-radius:8px;
    border:1px solid #e2e8f0;background:transparent;
    color:#94a3b8;font-size:14px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:all 0.2s;flex-shrink:0;
}
.modal-close:hover{background:#f1f5f9;color:#0f172a}
.modal-field{margin-bottom:14px}
.modal-field .lbl{font-size:11px;color:#94a3b8;letter-spacing:0.5px;margin-bottom:4px;text-transform:uppercase}
.modal-field .val{font-size:14px;color:#334155;word-break:break-all}
.modal-field .val.mono{font-family:"SF Mono","Fira Code","Consolas",monospace;font-size:15px;font-weight:600;color:#0f172a}
.modal-field .val a.phone-link{color:#3b82f6;text-decoration:none}
.modal-field .val a.phone-link:hover{text-decoration:underline}
.modal-actions{display:flex;gap:10px;margin-top:20px}
.modal-actions.single .action-btn{flex:1}
.modal-actions .action-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    padding:10px 18px;border-radius:10px;
    font-size:13px;font-weight:600;cursor:pointer;
    border:none;text-decoration:none;font-family:inherit;
    transition:all 0.25s;flex:1;
}
.modal-actions .action-btn.primary{
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    color:#fff;box-shadow:0 2px 8px rgba(59,130,246,0.2);
}
.modal-actions .action-btn.primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(59,130,246,0.3)}
.modal-actions .action-btn.ghost{
    background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;
    position:relative;
}
.modal-actions .action-btn.ghost:hover{
    background:#e2e8f0;border-color:#cbd5e1;
    transform:translateY(-1px);
    box-shadow:0 4px 12px rgba(0,0,0,0.06);
}
.modal-actions .action-btn.ghost .ico{color:#10b981;font-size:15px}
.modal-actions .action-btn.ghost:active{transform:translateY(0)}
.modal-actions .action-btn.copied{background:#10b981!important;color:#fff!important}

/* 个人资料弹窗 */
.profile-modal{max-width:420px}
.profile-modal .profile-header{
    text-align:center;margin-bottom:24px;
}
.profile-modal .profile-avatar{
    width:64px;height:64px;border-radius:50%;
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    color:#fff;display:inline-flex;align-items:center;justify-content:center;
    font-size:26px;font-weight:700;margin-bottom:12px;
    box-shadow:0 4px 16px rgba(59,130,246,0.25);
}
.profile-modal .profile-name{font-size:16px;font-weight:600;color:#0f172a}
.profile-modal .profile-sub{font-size:12px;color:#94a3b8;margin-top:2px}

.profile-tabs{
    display:flex;gap:0;margin-bottom:20px;
    background:#f1f5f9;border-radius:10px;padding:3px;
}
.profile-tab{
    flex:1;padding:8px 0;text-align:center;
    font-size:13px;font-weight:600;color:#64748b;
    border-radius:8px;cursor:pointer;
    transition:all 0.2s;border:none;background:transparent;font-family:inherit;
}
.profile-tab.active{
    background:#fff;color:#0f172a;
    box-shadow:0 1px 4px rgba(0,0,0,0.08);
}
.profile-tab:hover:not(.active){color:#334155}

.profile-form{display:none}
.profile-form.active{display:block}

.profile-field{margin-bottom:16px}
.profile-field label{
    display:block;font-size:12px;font-weight:600;
    color:#64748b;margin-bottom:6px;letter-spacing:0.3px;
}
.profile-field input{
    width:100%;padding:11px 14px;
    background:#f8fafc;border:1.5px solid #e2e8f0;
    border-radius:10px;font-size:14px;color:#0f172a;
    outline:none;transition:all 0.25s;font-family:inherit;
    -webkit-appearance:none;appearance:none;
}
.profile-field input::placeholder{color:#94a3b8}
.profile-field input:focus{
    background:#fff;border-color:#3b82f6;
    box-shadow:0 0 0 4px rgba(59,130,246,0.1);
}

.profile-submit{
    width:100%;padding:12px;
    background:linear-gradient(135deg,#3b82f6,#0ea5e9);
    color:#fff;border:none;border-radius:10px;
    font-size:14px;font-weight:600;cursor:pointer;
    font-family:inherit;transition:all 0.25s;
    box-shadow:0 2px 8px rgba(59,130,246,0.2);
    margin-top:8px;
}
.profile-submit:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(59,130,246,0.3)}
.profile-submit:active{transform:translateY(0)}
.profile-submit:disabled{opacity:0.5;cursor:not-allowed;transform:none}
.modal-actions .action-btn.disabled{opacity:0.4;pointer-events:none}

/* 空态 */
.empty{padding:48px 24px;text-align:center;color:#94a3b8;font-size:14px}
.empty .ico{font-size:36px;margin-bottom:10px}
.empty .txt{font-size:13px}

/* Toast */
.toast{
    position:fixed;bottom:32px;left:50%;transform:translateX(-50%);
    background:#0f172a;color:#fff;z-index:300;
    padding:12px 24px;border-radius:10px;
    font-size:13px;font-weight:500;
    box-shadow:0 8px 24px rgba(0,0,0,0.15);
    pointer-events:none;opacity:0;transition:opacity 0.3s;
}
.toast.show{opacity:1}

/* 响应式 */
@media(max-width:768px){
    .navbar{height:52px}
    .navbar-inner{padding:0 14px}
    .navbar .brand{gap:6px}
    .navbar .brand .brand-logo{width:28px;height:28px}
    .navbar .brand .brand-title{font-size:14px}
    .navbar .nav-actions{gap:4px}
    .navbar .user-chip{padding:4px 7px 4px 4px;font-size:10px}
    .navbar .user-chip .avatar{width:22px;height:22px;font-size:9px}
    .navbar .nav-icon-btn{padding:5px 10px;font-size:11px;border-radius:7px}
    .container{padding:14px}
    .stats-bar{gap:10px}
    .stats-bar .stat-card{padding:14px 12px}
    .stats-bar .stat-card .stat-inner .stat-label{font-size:13px}
    .record-card{padding:14px}
}
@media(max-width:480px){
    .navbar{padding:0 10px}
    .navbar-inner{padding:0 10px}
    .stats-bar{gap:8px}
    .stats-bar .stat-card{padding:12px 8px;border-radius:12px}
    .stats-bar .stat-card .stat-inner .stat-label{font-size:12px}
    .modal{padding:20px 18px;border-radius:16px}
    .profile-modal{max-width:100%}
    .profile-modal .profile-avatar{width:56px;height:56px;font-size:22px}
}
</style>
</head>
<body>

<!-- 星空 -->
<div class="stars" id="starsContainer"></div>
<!-- 光晕 -->
<div class="glow-orb glow-1"></div>
<div class="glow-orb glow-2"></div>
<div class="glow-orb glow-3"></div>

<!-- 顶栏 -->
<nav class="navbar">
    <div class="navbar-inner">
        <div class="brand">
            <img class="brand-logo" src="logo.png" alt="Logo" />
            <span class="brand-title">橘猫客户端</span>
        </div>
        <div class="nav-actions">
            <span class="user-chip" title="<?= htmlspecialchars($currentUser['username'] ?? '') ?>" onclick="openProfileModal()" style="cursor:pointer">
                <span class="avatar"><?= mb_strtoupper(mb_substr($currentUser['username'] ?? 'U', 0, 1, 'UTF-8')) ?></span>
                <span class="name"><?= htmlspecialchars($currentUser['username'] ?? '') ?></span>
            </span>
            <button class="nav-icon-btn refresh" id="navRefreshBtn" onclick="navRefreshClick()" title="刷新" aria-label="刷新">刷新</button>
            <button class="nav-icon-btn logout" onclick="confirmLogout()" title="退出" aria-label="退出">退出</button>
        </div>
    </div>
</nav>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- 详情弹窗 -->
<div class="modal-mask" id="modalMask" onclick="closeModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-head">
            <div class="type-icon" id="modalType"></div>
            <div>
                <div class="title" id="modalTitle">-</div>
                <div class="sub" id="modalSub">-</div>
            </div>
            <button class="modal-close" onclick="closeModal(true)" aria-label="关闭">✕</button>
        </div>
        <div class="modal-field">
            <div class="lbl">号码</div>
            <div class="val mono"><a id="modalPhone" class="phone-link" href="#" onclick="event.stopPropagation()">-</a></div>
        </div>
        <div class="modal-field" id="modalContentField">
            <div class="lbl">内容</div>
            <div class="val" id="modalContent">-</div>
        </div>
        <div class="modal-field">
            <div class="lbl">设备</div>
            <div class="val" id="modalDevice">-</div>
        </div>
        <div class="modal-field" id="modalLocationField">
            <div class="lbl">归属地</div>
            <div class="val" id="modalLocation">-</div>
        </div>
        <div class="modal-field">
            <div class="lbl">时间</div>
            <div class="val" id="modalTime">-</div>
        </div>

        <!-- 模态框底部操作区：主操作按钮，避免放在号码旁被误以为只复制号码 -->
        <!-- 拨打按钮默认隐藏（短信记录用不到），openDetail() 中按类型切换 -->
        <div class="modal-actions" id="modalActions">
            <button class="action-btn primary copy-btn" id="modalCopyBtn" type="button">
                <span class="ico" aria-hidden="true">⧉</span>
                <span class="txt">复制信息</span>
            </button>
            <a class="action-btn ghost" id="modalCallBtn" href="#" onclick="event.stopPropagation()" title="点击拨号" style="display:none">
                <span class="ico" aria-hidden="true">☎</span>
                <span class="txt">拨打</span>
            </a>
        </div>
    </div>
</div>

<div class="container">
    <!-- 时间筛选卡 -->
    <div class="stats-bar" id="statsBar">
        <div class="stat-card active" data-filter="today" onclick="setTimeFilter('today')">
            <div class="stat-inner">
                <div class="stat-label">😁今天</div>
            </div>
        </div>
        <div class="stat-card" data-filter="week" onclick="setTimeFilter('week')">
            <div class="stat-inner">
                <div class="stat-label">❤️最近7天</div>
            </div>
        </div>
        <div class="stat-card" data-filter="all" onclick="setTimeFilter('all')">
            <div class="stat-inner">
                <div class="stat-label">🫠全部</div>
            </div>
        </div>
    </div>

    <!-- 工具行 -->
    <div class="toolbar" id="toolbar">
        <div class="left">
            <span class="pulse"></span>
            <span>自动轮询中 · 每 5s 拉取新内容</span>
            <span>·</span>
            <span id="updatedAt">刚刚</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <span class="new-badge" id="newBadge" onclick="applyNewRecords()">↑ <span id="newCount">0</span> 条新</span>
        </div>
    </div>

    <!-- 记录列表 -->
    <div id="recordList" class="record-list"></div>

    <!-- 分页 -->
    <div class="pagination" id="pagination"></div>

    <!-- 个人资料弹窗 -->
    <div class="modal-mask" id="profileMask" onclick="closeProfileModal(event)">
        <div class="modal profile-modal" onclick="event.stopPropagation()">
            <div class="modal-head">
                <div style="flex:1"></div>
                <button class="modal-close" onclick="closeProfileModal(true)" aria-label="关闭">✕</button>
            </div>
            <div class="profile-header">
                <div class="profile-avatar" id="profileAvatar"><?= mb_strtoupper(mb_substr($currentUser['username'] ?? 'U', 0, 1, 'UTF-8')) ?></div>
                <div class="profile-name" id="profileName"><?= htmlspecialchars($currentUser['username'] ?? '') ?></div>
                <div class="profile-sub">管理您的账户信息</div>
            </div>

            <div class="profile-tabs">
                <button class="profile-tab active" data-tab="username" onclick="switchProfileTab('username')">修改用户名</button>
                <button class="profile-tab" data-tab="password" onclick="switchProfileTab('password')">修改密码</button>
            </div>

            <!-- 修改用户名 -->
            <div class="profile-form active" id="formUsername">
                <div class="profile-field">
                    <label>新用户名</label>
                    <input type="text" id="newUsername" maxlength="32"
                           autocapitalize="off" autocorrect="off" spellcheck="false"
                           placeholder="请输入新用户名（2-32个字符）">
                </div>
                <button class="profile-submit" id="btnChangeUsername" onclick="changeUsername()">确认修改</button>
            </div>

            <!-- 修改密码 -->
            <div class="profile-form" id="formPassword">
                <div class="profile-field">
                    <label>旧密码</label>
                    <input type="password" id="oldPassword" placeholder="请输入当前密码">
                </div>
                <div class="profile-field">
                    <label>新密码</label>
                    <input type="password" id="newPassword" placeholder="请输入新密码">
                </div>
                <div class="profile-field">
                    <label>确认新密码</label>
                    <input type="password" id="confirmPassword" placeholder="请再次输入新密码">
                </div>
                <button class="profile-submit" id="btnChangePassword" onclick="changePassword()">确认修改</button>
            </div>
        </div>
    </div>
</div>

<script>
// CSRF token（由服务端注入）
const CSRF_TOKEN = '<?= csrfToken() ?>';

// AES 加密工具函数（基于 Web Crypto API，与 login.php 保持一致）
async function aesEncrypt(key, plaintext) {
    var iv = crypto.getRandomValues(new Uint8Array(16));
    var keyBytes = new TextEncoder().encode(key);
    var paddedKey = new Uint8Array(32);
    paddedKey.set(keyBytes.slice(0, 32));
    keyBytes = paddedKey;
    var cryptoKey = await crypto.subtle.importKey(
        'raw', keyBytes, { name: 'AES-CBC' }, false, ['encrypt']
    );
    var encrypted = await crypto.subtle.encrypt(
        { name: 'AES-CBC', iv: iv },
        cryptoKey,
        new TextEncoder().encode(plaintext)
    );
    var result = new Uint8Array(iv.length + encrypted.byteLength);
    result.set(iv, 0);
    result.set(new Uint8Array(encrypted), iv.length);
    return btoa(String.fromCharCode.apply(null, result));
}

// ── 星空粒子 ──
(function(){
    var c = document.getElementById('starsContainer');
    if(!c) return;
    var frag = document.createDocumentFragment();
    for(var i=0;i<100;i++){
        var s = document.createElement('div');
        s.className = 'star';
        s.style.cssText = [
            'left:'+Math.random()*100+'%',
            'top:'+Math.random()*100+'%',
            'width:'+(1+Math.random()*2.5)+'px',
            'height:'+(1+Math.random()*2.5)+'px',
            '--d:'+(2.5+Math.random()*5)+'s',
            '--delay:'+Math.random()*5+'s',
            '--o:'+(0.25+Math.random()*0.5)
        ].join(';');
        frag.appendChild(s);
    }
    c.appendChild(frag);
})();


const API_BASE = 'api';
let currentPage  = 1;
let totalPages   = 1;
let activeTimeFilter = 'today'; // 'today', 'week', 'all'
let isLoading    = false;

// ====== 增量跟踪状态 ======
// 全部已加载记录的最大 id（与当前筛选无关，跨筛选保持）
let lastMaxId  = 0;
// 当前可见的 id 集合（防止重复显示）
let visibleIds = new Set();
// 后台攒着的新记录（等用户回首页再展示，或者自动 prepend）
let pendingNew = [];
let newCount   = 0;

// ====== 工具 ======
function showToast(msg){
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 2000);
}

// 退出登录前确认，避免误点
function confirmLogout(){
    if (window.confirm('确定要退出登录吗？')) {
        window.location.href = 'logout.php';
    }
}

// ====== 个人资料弹窗 ======
function openProfileModal(){
    document.getElementById('profileMask').classList.add('open');
    document.body.style.overflow = 'hidden';
    // 清空表单
    document.getElementById('newUsername').value = '';
    document.getElementById('oldPassword').value = '';
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    // 默认切到用户名 tab
    switchProfileTab('username');
}

function closeProfileModal(ev){
    if (ev === true || (ev && ev.target === document.getElementById('profileMask'))) {
        document.getElementById('profileMask').classList.remove('open');
        document.body.style.overflow = '';
    }
}

function switchProfileTab(tab){
    document.querySelectorAll('.profile-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.tab === tab);
    });
    document.getElementById('formUsername').classList.toggle('active', tab === 'username');
    document.getElementById('formPassword').classList.toggle('active', tab === 'password');
}

// 修改用户名
async function changeUsername(){
    const input = document.getElementById('newUsername');
    const btn = document.getElementById('btnChangeUsername');
    const newUsername = input.value.trim();

    if (!newUsername) { showToast('请输入新用户名'); return; }
    if (newUsername.length < 2 || newUsername.length > 32) { showToast('用户名长度2-32个字符'); return; }

    btn.disabled = true;
    btn.textContent = '提交中...';

    try {
        const r = await fetch(API_BASE + '/profile.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_username', new_username: newUsername, csrf: CSRF_TOKEN })
        });
        const d = await r.json();
        if (d.code === 200) {
            showToast('用户名修改成功');
            // 更新页面显示
            document.getElementById('profileName').textContent = d.username;
            document.getElementById('profileAvatar').textContent = d.username.charAt(0).toUpperCase();
            document.querySelector('.user-chip .name').textContent = d.username;
            document.querySelector('.user-chip .avatar').textContent = d.username.charAt(0).toUpperCase();
            document.querySelector('.user-chip').title = d.username;
            input.value = '';
        } else {
            showToast(d.msg || '修改失败');
        }
    } catch(e) {
        showToast('网络错误');
    } finally {
        btn.disabled = false;
        btn.textContent = '确认修改';
    }
}

// 修改密码
async function changePassword(){
    const oldPw = document.getElementById('oldPassword').value;
    const newPw = document.getElementById('newPassword').value;
    const confirmPw = document.getElementById('confirmPassword').value;
    const btn = document.getElementById('btnChangePassword');

    if (!oldPw || !newPw || !confirmPw) { showToast('请填写所有字段'); return; }
    if (newPw !== confirmPw) { showToast('两次输入的新密码不一致'); return; }
    if (oldPw === newPw) { showToast('新密码不能与旧密码相同'); return; }

    btn.disabled = true;
    btn.textContent = '提交中...';

    try {
        // AES 加密密码（与 login.php 保持一致）
        const encryptionKey = 'sms_encryption_key_32bytes!';
        const oldPwEnc = await aesEncrypt(encryptionKey, oldPw);
        const newPwEnc = await aesEncrypt(encryptionKey, newPw);
        const confirmPwEnc = await aesEncrypt(encryptionKey, confirmPw);

        const r = await fetch(API_BASE + '/profile.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'change_password',
                old_password_encrypted: oldPwEnc,
                new_password_encrypted: newPwEnc,
                confirm_password_encrypted: confirmPwEnc,
                csrf: CSRF_TOKEN
            })
        });
        const d = await r.json();
        if (d.code === 200) {
            showToast('密码修改成功，请重新登录');
            // 延迟跳转登录页
            setTimeout(() => { window.location.href = 'logout.php'; }, 1200);
        } else {
            showToast(d.msg || '修改失败');
        }
    } catch(e) {
        showToast('网络错误');
    } finally {
        btn.disabled = false;
        btn.textContent = '确认修改';
    }
}

// 顶栏刷新按钮：触发旋转动画 + 拉数据
function navRefreshClick(){
    const btn = document.getElementById('navRefreshBtn');
    if (btn) {
        // 移除再加 class，重新触发动画（防止连续点击无动画）
        btn.classList.remove('spinning');
        // 强制重排后重新添加
        void btn.offsetWidth;
        btn.classList.add('spinning');
        setTimeout(() => btn.classList.remove('spinning'), 800);
    }
    refreshData(true);
}

async function apiGet(path, opts){
    // 走浏览器默认缓存策略：让 ETag/Cache-Control 真正生效（304 时零字节返回）
    const r = await fetch(API_BASE + '/' + path.replace(/^\//, ''), Object.assign({
        credentials: 'same-origin',
        cache: 'default'
    }, opts || {}));
    if (r.status === 401) { window.location.href = 'login.php'; throw new Error('未登录'); }
    // 304 Not Modified：业务上等价于空响应，调用方需自行处理
    if (r.status === 304) return { _notModified: true };
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const j = await r.json();
    if (j.code !== 200) throw new Error(j.msg || '请求失败');
    return j;
}

function escHtml(s){ return s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s){ return escHtml(s); }

function timeAgo(iso){
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff/60) + ' 分钟前';
    if (diff < 86400) return Math.floor(diff/3600) + ' 小时前';
    if (diff < 86400*7) return Math.floor(diff/86400) + ' 天前';
    return iso;
}
function fmtTime(iso){
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    const m = ('0' + (d.getMonth()+1)).slice(-2);
    const day = ('0' + d.getDate()).slice(-2);
    const hh = ('0' + d.getHours()).slice(-2);
    const mm = ('0' + d.getMinutes()).slice(-2);
    return `${d.getFullYear()}-${m}-${day} ${hh}:${mm}`;
}

// ====== 构建单个记录卡片（返回 HTMLElement） ======
function buildRecordCard(r){
    const isSms = r.type === 'sms';
    const icon = isSms ? '📩' : '📞';  // 使用 emoji 图标，更可靠
    const typeLabel = isSms ? '短信' : '来电';
    const content = r.content || (isSms ? '（无内容）' : '（无通话信息）');
    const location = r.location || '';
    const device = r.device_name || '';
    const phone = r.phone_number || '';
    const time = r.event_time || '';

    const card = document.createElement('div');
    card.className = 'record-card';
    card.dataset.id = r.id;
    card.dataset.phone = phone;
    card.dataset.content = content;
    card.dataset.type = isSms ? 'sms' : 'call';
    card.dataset.device = device;
    card.dataset.location = location;
    card.dataset.time = time;
    card.onclick = function(){ openDetail(this); };

    // 号码展示：来电时渲染为可拨打的 tel: 链接，点击不打开详情
    let phoneHtml;
    if (phone) {
        if (isSms) {
            phoneHtml = `<span class="phone">${escHtml(phone)}</span>`;
        } else {
            const telHref = 'tel:' + phone.replace(/[^\d+]/g, '');
            phoneHtml = `<a class="phone phone-link" href="${escAttr(telHref)}" onclick="event.stopPropagation()" title="点击拨打 ${escAttr(phone)}"><span>${escHtml(phone)}</span></a>`;
        }
    } else {
        phoneHtml = `<span class="phone">未知号码</span>`;
    }

    card.innerHTML = `
        <div class="record-body">
            <div class="row1">
                <div class="type-icon ${isSms ? 'sms' : 'call'}">${icon}</div>
                ${phoneHtml}
                <span class="type-label ${isSms ? 'sms' : 'call'}">${typeLabel}</span>
            </div>
            <div class="content-text">${escHtml(content)}</div>
            <div class="meta-row">
                ${!isSms && location ? '<span>📍 ' + escHtml(location) + '</span>' : ''}
                ${device ? '<span>📱 ' + escHtml(device) + '</span>' : ''}
                <span>🕒 ${escHtml(timeAgo(time))}</span>
            </div>
        </div>
        <span class="chevron">›</span>`;
    return card;
}

// ====== 时间筛选（点击筛选卡） ======
function setTimeFilter(filter){
    activeTimeFilter = filter;
    document.querySelectorAll('.stat-card').forEach(c => {
        c.classList.toggle('active', c.dataset.filter === filter);
    });
    currentPage = 1;
    pendingNew = [];
    newCount = 0;
    updateNewBadge();
    // 切换筛选时重置增量状态
    lastMaxId = 0;
    visibleIds = new Set();
    refreshData();
}

// ====== 加载 ======
async function refreshData(manual){
    if (isLoading) return;
    isLoading = true;

    const listEl = document.getElementById('recordList');
    if (manual) listEl.style.opacity = '0.4';

    try {
        // 计算时间筛选参数（只传日期，不传时间）
        let timeParam = '';
        const now = new Date();
        if (activeTimeFilter === 'today') {
            // 今天：YYYY-MM-DD
            const today = now.getFullYear() + '-' +
                          String(now.getMonth() + 1).padStart(2, '0') + '-' +
                          String(now.getDate()).padStart(2, '0');
            timeParam = '&since=' + today;
        } else if (activeTimeFilter === 'week') {
            // 7天前的日期：YYYY-MM-DD
            const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
            const weekDate = weekAgo.getFullYear() + '-' +
                             String(weekAgo.getMonth() + 1).padStart(2, '0') + '-' +
                             String(weekAgo.getDate()).padStart(2, '0');
            timeParam = '&since=' + weekDate;
        }
        // 'all' 不需要时间参数

        // 是否走增量：仅在第 1 页 + 今天模式 + 已初始化时
        const canDelta = (currentPage === 1)
                      && (activeTimeFilter === 'today')
                      && (lastMaxId > 0);

        // 构建请求URL
        const dataUrl = canDelta
            ? 'list.php?page=1&limit=20&since_id=' + lastMaxId + timeParam
            : 'list.php?page=' + currentPage + '&limit=20' + timeParam;

        const data = await apiGet(dataUrl);
        // 304 命中：什么都不做，零流量
        if (data._notModified) return;

        const d = data.data;
        totalPages = d.total_pages;

        if (canDelta && d.is_delta) {
            // 增量请求：检测数据库是否被清空
            const globalMax = Number(d.global_max_id ?? d.max_id ?? 0);
            if (globalMax < lastMaxId) {
                // 数据库被清空：重置增量跟踪状态，强制走一次全量
                lastMaxId = 0;
                visibleIds = new Set();
                pendingNew = [];
                newCount = 0;
                updateNewBadge();
                showToast('数据库已重置，正在重新加载…');
                refreshData();
                return;
            }
            // 增量：拿到的是 id > lastMaxId 的新记录
            handleDeltaUpdate(d.records, d.max_id);
        } else {
            // 全量：覆盖渲染
            renderRecords(d.records, d.total);
            visibleIds = new Set((d.records || []).map(r => Number(r.id)));
            if (d.max_id && Number(d.max_id) > lastMaxId) lastMaxId = Number(d.max_id);
        }

        // 写一个真实 HH:MM:SS 时间戳
        const _d2 = new Date();
        document.getElementById('updatedAt').textContent =
            ('0'+_d2.getHours()).slice(-2)+':'+('0'+_d2.getMinutes()).slice(-2)+':'+('0'+_d2.getSeconds()).slice(-2);
    } catch(e){
        if (e.message !== '未登录') {
            listEl.innerHTML = '<div class="empty"><div class="ico">⚠</div><div class="txt">' + escHtml(e.message) + '</div></div>';
        }
    } finally {
        isLoading = false;
        listEl.style.opacity = '';
    }
}

// 增量更新：去重 + 顶部插入 + 挤下动画
function handleDeltaUpdate(records, maxId){
    if (!records || records.length === 0) return;

    // 1. 去重
    const fresh = records.filter(r => {
        const id = Number(r.id);
        if (visibleIds.has(id)) return false;
        return true;
    });

    if (fresh.length === 0) {
        // 没有匹配筛选的新内容，但服务器可能已记录
        if (maxId && Number(maxId) > lastMaxId) lastMaxId = Number(maxId);
        return;
    }

    // 2. 增量插入到顶部（带"挤下"动画）
    prependWithFlip(fresh);

    // 3. 更新 lastMaxId 与可见 id
    let mx = lastMaxId;
    fresh.forEach(r => {
        const id = Number(r.id);
        visibleIds.add(id);
        if (id > mx) mx = id;
    });
    if (Number(maxId) > mx) mx = Number(maxId);
    lastMaxId = mx;
}

function prependWithFlip(records){
    const list = document.getElementById('recordList');
    // 移除空态
    const empty = list.querySelector('.empty');
    if (empty) empty.remove();

    // ===== FLIP: First（记录旧位置）=====
    const existing = Array.from(list.querySelectorAll('.record-card'));
    const firstRects = new Map();
    existing.forEach(el => firstRects.set(el, el.getBoundingClientRect().top));

    // ===== Last：插入新卡到顶部（newest 在最上）=====
    // 接口返回的 records 已按 id DESC 排好，reverse 让最老的新卡先插入，最新的最后插入（在最顶）
    const newCards = [];
    [...records].reverse().forEach(r => {
        const card = buildRecordCard(r);
        card.classList.add('prepend-new', 'prepend-flash');
        list.insertBefore(card, list.firstChild);
        newCards.push(card);
    });

    // ===== Invert：把旧卡瞬移到"原位"（transform 反向偏移）=====
    existing.forEach(el => {
        const last = el.getBoundingClientRect().top;
        const first = firstRects.get(el);
        const dy = last - first;
        if (dy > 0) {
            el.style.transition = 'none';
            el.style.transform = 'translateY(' + (-dy) + 'px)';
            el.style.willChange = 'transform';
        }
    });

    // 强制 reflow
    void list.offsetHeight;

    // ===== Play：下一帧把 transform 撤掉，触发过渡 =====
    requestAnimationFrame(() => {
        existing.forEach(el => {
            if (el.style.transform) {
                el.style.transition = 'transform 0.45s cubic-bezier(.2,.7,.2,1)';
                el.style.transform = '';
            }
        });
        // 动画结束后清理内联 style
        setTimeout(() => {
            existing.forEach(el => {
                el.style.transition = '';
                el.style.willChange = '';
            });
        }, 520);

        // 新卡的 flash 高亮逐步消失
        newCards.forEach((card, i) => {
            setTimeout(() => {
                card.classList.remove('prepend-new');
                setTimeout(() => card.classList.remove('prepend-flash'), 1400);
            }, 80 + i * 50);
        });
    });
}

// ====== 全量渲染（首次/翻页/换筛选） ======
function renderRecords(records, total){
    const listEl = document.getElementById('recordList');
    if (!records || records.length === 0){
        const timeLabel = activeTimeFilter === 'today' ? '今天' : activeTimeFilter === 'week' ? '最近7天' : '';
        listEl.innerHTML = '<div class="empty"><div class="ico">📭</div><div class="txt">暂无' + timeLabel + '记录</div></div>';
        renderPagination(total, 0);
        return;
    }
    // 清空再插入
    listEl.innerHTML = '';
    const frag = document.createDocumentFragment();
    records.forEach((r, i) => {
        const card = buildRecordCard(r);
        // 错落进入
        card.style.animationDelay = (Math.min(i, 8) * 40) + 'ms';
        frag.appendChild(card);
    });
    listEl.appendChild(frag);
    renderPagination(total, records.length);
}

function renderPagination(total, count){
    const el = document.getElementById('pagination');
    if (count === 0 || totalPages <= 1) { el.innerHTML = ''; return; }
    let btns = '';
    btns += '<button ' + (currentPage <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (currentPage-1) + ')">‹</button>';
    const max = 5;
    let s = Math.max(1, currentPage - Math.floor(max/2));
    let e = Math.min(totalPages, s + max - 1);
    if (e - s < max - 1) s = Math.max(1, e - max + 1);
    for (let i = s; i <= e; i++){
        btns += '<button class="' + (i === currentPage ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    btns += '<button ' + (currentPage >= totalPages ? 'disabled' : '') + ' onclick="goPage(' + (currentPage+1) + ')">›</button>';
    el.innerHTML = '<div class="page-btns">' + btns + '</div>'
                 + '<div class="page-info">共 <b>' + total.toLocaleString() + '</b> 条 / <b>' + totalPages + '</b> 页</div>';
}

function goPage(p){
    if (p < 1 || p > totalPages) return;
    currentPage = p;
    pendingNew = [];
    newCount = 0;
    updateNewBadge();
    // 翻页必须全量加载
    lastMaxId = 0;
    visibleIds = new Set();
    refreshData();
    // 滚到统计卡位置（顶栏下方）
    requestAnimationFrame(() => {
        document.querySelector('.stats-bar').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

// ====== 角标：自动刷新期间收到新内容，但当前不在首页 ======
function updateNewBadge(){
    const badge = document.getElementById('newBadge');
    const span  = document.getElementById('newCount');
    if (newCount > 0) {
        span.textContent = newCount > 99 ? '99+' : newCount;
        badge.classList.add('show');
    } else {
        badge.classList.remove('show');
    }
}
function applyNewRecords(){
    // 用户点角标：滚到顶部 + 强制走一次全量刷新（落到第一页可见）
    currentPage = 1;
    pendingNew = [];
    newCount = 0;
    updateNewBadge();
    // 强制全量
    lastMaxId = 0;
    visibleIds = new Set();
    refreshData();
    document.querySelector('.stats-bar').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ====== 自动轮询：每 5s 用 since_id 拉一次新内容 ======
// 比起 SSE 简单、稳，不占服务端长连接；新内容直接插到顶部
let pollTimer      = null;
const POLL_INTERVAL = 5000;     // 5 秒一次
let pollInFlight   = false;     // 防止上一次还没回来就发起下一次
let lastPollAt     = 0;         // 用于页面切到后台时暂停

// 拉取 since_id 之后的新记录（同时带上当前时间筛选）
function pollNew(){
    if (pollInFlight) return;
    pollInFlight = true;

    // 计算时间筛选参数（与 refreshData 保持一致）
    let timeParam = '';
    const now = new Date();
    if (activeTimeFilter === 'today') {
        const today = now.getFullYear() + '-' +
                      String(now.getMonth() + 1).padStart(2, '0') + '-' +
                      String(now.getDate()).padStart(2, '0');
        timeParam = '&since=' + today;
    } else if (activeTimeFilter === 'week') {
        const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
        const weekDate = weekAgo.getFullYear() + '-' +
                         String(weekAgo.getMonth() + 1).padStart(2, '0') + '-' +
                         String(weekAgo.getDate()).padStart(2, '0');
        timeParam = '&since=' + weekDate;
    }

    const url = 'list.php?page=1&limit=20&since_id=' + (lastMaxId || 0) + timeParam;
    apiGet(url)
        .then(d => {
            if (!d) return;
            // 兼容 API 返回格式：可能是 {ok: true, records: [...]} 或 {code: 200, data: {...}}
            const records = d.records || (d.data && d.data.records) || [];
            const maxId = d.max_id || (d.data && d.data.max_id);
            
            // 优先更新 max_id（即使没有新记录，也要推进指针）
            // 关键修复：不要因为 records 为空就跳过更新
            if (maxId && Number(maxId) > lastMaxId) {
                lastMaxId = Number(maxId);
            }
            
            // 有新内容且在第一页时才插入，其他页面只更新指针
            if (records.length > 0 && currentPage === 1) {
                handleDeltaUpdate(records, maxId);
            }
        })
        .catch(_ => { /* 静默失败，下一轮继续 */ })
        .finally(() => {
            pollInFlight = false;
            lastPollAt = Date.now();
            // 把"刚刚"更新为这次拉取的真实时间 HH:MM:SS
            const upd = document.getElementById('updatedAt');
            if (upd) {
                const _d = new Date();
                upd.textContent =
                    ('0'+_d.getHours()).slice(-2) + ':' +
                    ('0'+_d.getMinutes()).slice(-2) + ':' +
                    ('0'+_d.getSeconds()).slice(-2);
            }
        });
}

// 启动轮询
function startPolling(){
    if (pollTimer) return;
    // 立即跑一次，再按间隔
    pollNew();
    pollTimer = setInterval(pollNew, POLL_INTERVAL);
}

// 停止轮询
function stopPolling(){
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

// 页面切后台暂停（节流），切回前台立刻补一次
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopPolling();
    } else {
        startPolling();
    }
});

// ====== 详情弹窗 ======
let touchStartY = 0;
let touchCurrentY = 0;
let isDragging = false;

function openDetail(el){
    const isSms = el.dataset.type === 'sms';
    const phone = el.dataset.phone || '-';
    const content = el.dataset.content || '';
    const device = el.dataset.device || '-';
    const location = el.dataset.location || '-';
    const time = el.dataset.time || '-';
    const fmtTimeVal = fmtTime(time) || '-';

    const typeIcon = document.getElementById('modalType');
    typeIcon.className = 'type-icon ' + (isSms ? 'sms' : 'call');
    typeIcon.textContent = isSms ? '📩' : '📞';  // 使用 emoji 图标

    document.getElementById('modalTitle').textContent = isSms ? '短信详情' : '电话详情';
    document.getElementById('modalSub').textContent = isSms ? 'SMS Message' : 'Incoming Call';

    // 号码：渲染为可拨打的 tel: 链接
    const phoneEl = document.getElementById('modalPhone');
    phoneEl.textContent = phone;
    if (phone && phone !== '-') {
        const telHref = 'tel:' + phone.replace(/[^\d+]/g, '');
        phoneEl.setAttribute('href', telHref);
        phoneEl.setAttribute('title', '点击拨打 ' + phone);
    } else {
        phoneEl.setAttribute('href', '#');
        phoneEl.removeAttribute('title');
    }

    document.getElementById('modalDevice').textContent = device || '-';
    document.getElementById('modalLocation').textContent = location || '-';
    document.getElementById('modalTime').textContent = fmtTimeVal;

    // 归属地字段：短信记录隐藏，来电记录显示
    const locationField = document.getElementById('modalLocationField');
    const contentField = document.getElementById('modalContentField');
    if (isSms) {
        contentField.style.display = '';
        locationField.style.display = 'none';  // 短信隐藏归属地
        document.getElementById('modalContent').textContent = content || '（无内容）';
    } else {
        contentField.style.display = 'none';
        locationField.style.display = '';  // 来电显示归属地
    }

    // 根据类型构建要复制的完整文本
    const copyBtn = document.getElementById('modalCopyBtn');
    let fullText;
    if (isSms) {
        fullText = '号码：' + phone + '\n内容：' + (content || '（无内容）');
    } else {
        fullText = '号码：' + phone + '\n归属地：' + (location || '-') + '\n时间：' + fmtTimeVal;
    }
    copyBtn.dataset.full = fullText;
    copyBtn.classList.remove('copied');
    // 还原按钮文字（之前可能变 "✓ 已复制"）
    const txtSpan = copyBtn.querySelector('.txt');
    if (txtSpan) txtSpan.textContent = '复制信息';

    // 拨号按钮：仅来电记录才显示（短信记录不需要拨号）
    const callBtn = document.getElementById('modalCallBtn');
    const actionsEl = document.getElementById('modalActions');
    if (isSms) {
        // 短信：隐藏拨号，复制按钮占满
        callBtn.style.display = 'none';
        actionsEl.classList.add('single');
    } else {
        callBtn.style.display = '';
        actionsEl.classList.remove('single');
        if (phone && phone !== '-') {
            callBtn.setAttribute('href', 'tel:' + phone.replace(/[^\d+]/g, ''));
            callBtn.classList.remove('disabled');
            callBtn.setAttribute('title', '点击拨打 ' + phone);
        } else {
            callBtn.setAttribute('href', '#');
            callBtn.classList.add('disabled');
            callBtn.removeAttribute('title');
        }
    }

    document.getElementById('modalMask').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(ev){
    if (ev === true || (ev && ev.target.id === 'modalMask')) {
        document.getElementById('modalMask').classList.remove('open');
        document.body.style.overflow = '';
    }
}
// 复制到剪贴板：优先使用异步 Clipboard API（HTTPS 下可靠），失败时回退到 execCommand
async function copyText(text){
    if (text == null || text === '') return false;
    // 1) 现代 API：要求安全上下文（HTTPS / localhost）
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch(_) { /* 继续走回退 */ }
    // 2) 回退方案：临时 textarea + execCommand
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.left = '0';
        ta.style.width = '1px';
        ta.style.height = '1px';
        ta.style.padding = '0';
        ta.style.border = 'none';
        ta.style.outline = 'none';
        ta.style.boxShadow = 'none';
        ta.style.background = 'transparent';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        // iOS 兼容
        const range = document.createRange();
        range.selectNodeContents(ta);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.setSelectionRange(0, text.length);
        ta.focus();
        const ok = document.execCommand('copy');
        sel.removeAllRanges();
        document.body.removeChild(ta);
        return !!ok;
    } catch(_) {
        return false;
    }
}

// 复制按钮处理：使用 capture 阶段触发，避开模态框 stopPropagation 的拦截
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;
    // 阻断后续事件：避免打开/关闭模态框、跳转等副作用
    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();

    // 优先取 data-full（详情弹窗按类型预组装的完整文本）
    let text = btn.dataset.full || '';
    if (!text && btn.dataset.copy) {
        const el = document.getElementById(btn.dataset.copy);
        if (el) text = (el.textContent || el.value || '').trim();
    }
    if (!text) { showToast('没有可复制的内容'); return; }

    const txtSpan = btn.querySelector('.txt');
    const original = txtSpan ? txtSpan.textContent : (btn.textContent || '复制');
    const ok = await copyText(text);
    if (ok) {
        btn.classList.add('copied');
        if (txtSpan) txtSpan.textContent = '✓ 已复制';
        else btn.textContent = '✓ 已复制';
        setTimeout(() => {
            btn.classList.remove('copied');
            if (txtSpan) txtSpan.textContent = original;
            else btn.textContent = original;
        }, 1500);
    } else {
        showToast('复制失败，请长按选择手动复制');
    }
}, true); // ★ 关键：捕获阶段，绕过 .modal 的 stopPropagation

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal(true);
});

// ====== 初始化 ======
function init(){
    setTimeFilter('today');
    refreshData();
    // 启动 5 秒轮询：拉新内容、插到顶部
    startPolling();
}
init();
</script>
</body>
</html>
