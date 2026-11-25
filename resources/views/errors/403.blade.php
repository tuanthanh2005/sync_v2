@extends('errors::minimal')

@section('title', app()->isLocale('vi') ? 'Không có quyền truy cập' : 'Forbidden')
@section('code', '403')
@section('heading', app()->isLocale('vi') ? 'Không có quyền truy cập' : 'Access denied')
@section('message', app()->isLocale('vi') ? 'Bạn không có quyền truy cập tài nguyên này. Nếu bạn cho rằng đây là nhầm lẫn, vui lòng liên hệ quản trị viên.' : 'You do not have permission to access this resource. Contact support if you believe this is an error.')
