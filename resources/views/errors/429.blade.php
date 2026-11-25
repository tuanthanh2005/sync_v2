@extends('errors::minimal')

@section('title', app()->isLocale('vi') ? 'Quá nhiều yêu cầu' : 'Too Many Requests')
@section('code', '429')
@section('heading', app()->isLocale('vi') ? 'Thao tác quá nhanh' : 'Too many requests')
@section('message', app()->isLocale('vi') ? 'Bạn vừa gửi quá nhiều yêu cầu trong thời gian ngắn. Vui lòng đợi một chút rồi thử lại.' : 'You have made too many requests recently. Please wait a moment and try again.')
