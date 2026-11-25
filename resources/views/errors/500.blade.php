@extends('errors::minimal')

@section('title', app()->isLocale('vi') ? 'Lỗi máy chủ' : 'Server Error')
@section('code', '500')
@section('heading', app()->isLocale('vi') ? 'Đã xảy ra sự cố hệ thống' : 'Something went wrong')
@section('message', app()->isLocale('vi') ? 'Hệ thống đang gặp sự cố tạm thời. Đội ngũ kỹ thuật đã ghi nhận và sẽ khắc phục sớm nhất.' : 'We encountered an unexpected server issue. Please try again in a few moments.')
