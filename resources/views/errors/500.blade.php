@extends('errors::minimal')

@section('title', __('Server Error'))
@section('code', '500')
@section('heading', __('Something went wrong'))
@section('message', __('We encountered an unexpected server issue. Please try again in a few moments.'))
