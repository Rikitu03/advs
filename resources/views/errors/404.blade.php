@php($code = 404)
@php($icon = 'magnifying-glass')

@extends('errors.minimal')

@section('code', '404')
@section('title', 'Page not found')
@section('message', 'The page you are looking for was moved, removed, or never existed.')
