@php
    $field = $field ?? '__mad_form';
    $token = \Mad\Form\MadFormRegistry::token();
@endphp
@if($token)
<input type="hidden" name="{{ $field }}" value="{{ $token }}">
@endif
