@extends('errors::minimal')

@section('title', __('Too Many Requests'))
@section('code', '429')
@section('heading', __('Too many requests'))
@section('message', __('You have made too many requests recently. Please wait a moment and try again.'))
