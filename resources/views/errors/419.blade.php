@extends('errors::minimal')

@section('title', app()->isLocale('vi') ? 'Phiên làm việc đã hết hạn' : 'Page Expired')
@section('code', '419')
@section('heading', app()->isLocale('vi') ? 'Phiên làm việc đã hết hạn' : 'Session expired')
@section('message', app()->isLocale('vi') ? 'Phiên xác thực đã hết hạn. Vui lòng tải lại trang và thử thao tác lại.' : 'Your session has expired. Please refresh the page and try again.')
