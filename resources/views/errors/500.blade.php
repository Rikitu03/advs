@php($code = 500)
@php($icon = 'exclamation-triangle')

@extends('errors.minimal')

@section('code', '500')
@section('title', 'Server error')
@section('message', 'Something went wrong on our end. The issue has been logged — please try again in a moment.')
