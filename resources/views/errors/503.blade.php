@extends('errors::minimal')

@section('title', app()->isLocale('vi') ? 'Hệ thống đang bảo trì' : 'Service Unavailable')
@section('code', '503')
@section('heading', app()->isLocale('vi') ? 'Hệ thống đang tạm bảo trì' : 'Service unavailable')
@section('message', app()->isLocale('vi') ? 'SyncViet đang bảo trì hoặc quá tải tạm thời. Vui lòng quay lại sau ít phút.' : 'The service is temporarily unavailable due to maintenance or high load. Please check back shortly.')
