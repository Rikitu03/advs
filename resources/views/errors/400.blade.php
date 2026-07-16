@php($code = 400)
@php($icon = 'exclamation-circle')

@extends('errors.minimal')

@section('code', '400')
@section('title', 'Bad request')
@section('message', 'The request could not be understood by the server. Please go back and try again.')
