@extends('errors::minimal')

@section('title', app()->isLocale('vi') ? 'Không tìm thấy trang' : 'Page Not Found')
@section('code', '404')
@section('heading', app()->isLocale('vi') ? 'Không tìm thấy trang' : 'Page not found')
@section('message', app()->isLocale('vi') ? 'Liên kết bạn truy cập không tồn tại, đã bị xóa hoặc nội dung hiện không khả dụng.' : 'The page you are looking for does not exist, has been removed, or is currently unavailable.')
