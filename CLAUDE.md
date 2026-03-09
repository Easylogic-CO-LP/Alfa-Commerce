# CLAUDE.md — EasyLogic E-Commerce Project

## Project Overview
Joomla-based e-commerce platform built with PHP, JavaScript, and MySQL.

## Tech Stack
- CMS: Joomla 4.x / 5.x
- Backend: PHP 8.x
- Frontend: JavaScript (vanilla + jQuery)
- Database: MySQL / MariaDB
- Server: Apache / Nginx

## Key Directories
- /components/     → Joomla components (MVC)
- /modules/        → Joomla modules
- /plugins/        → Joomla plugins
- /templates/      → Frontend templates
- /administrator/  → Admin panel extensions

## Coding Standards
- PSR-12 for PHP
- Joomla Coding Standards
- No inline SQL — use Joomla DB class
- Always sanitize input with Joomla InputFilter

## Database
- MySQL with Joomla prefix (#__ tables)
- Use JDatabaseDriver for all queries

## Commands
- Run locally: XAMPP / WAMP / Laravel Herd
- Deploy: FTP / SSH to cPanel
