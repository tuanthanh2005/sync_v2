@extends('errors::minimal')

@section('title', __('Forbidden'))
@section('code', '403')
@section('heading', __('Access denied'))
@section('message', __('You do not have permission to access this resource. Contact support if you believe this is an error.'))
