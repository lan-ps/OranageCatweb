<?php
/**
 * 用户退出登录接口
 * 
 * 执行完整的用户退出操作，包括：
 * 1. 清除"记住我"Cookie及数据库中的对应token记录
 * 2. 销毁当前会话（Session）
 * 3. 清除会话Cookie
 * 4. 重定向到登录页面
 * 
 * 访问方式: GET /logout.php
 * 
 * 执行流程:
 * 1. 初始化应用环境（定义 APP_STARTED 常量）
 * 2. 加载配置文件，建立会话
 * 3. 调用 logoutUser() 执行完整退出逻辑
 * 4. 重定向到登录页面
 * 
 * 安全特性:
 * - 清除数据库中该用户的所有 remember_tokens，防止旧设备继续自动登录
 * - 销毁服务器端会话数据
 * - 清除客户端会话Cookie
 * - 确保完全退出，不留登录痕迹
 */
define('APP_STARTED', true);
require_once __DIR__ . '/config.php';

// 执行退出登录操作
logoutUser();

// 重定向到登录页面
header('Location: login.php');
exit;
