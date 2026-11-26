@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('heading', __('Service unavailable'))
@section('message', __('The service is temporarily unavailable due to maintenance or high load. Please check back shortly.'))
