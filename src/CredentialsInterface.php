<?php

namespace App;

interface CredentialsInterface {
    public function getUsername(): string;
    public function getApiKey(): \SensitiveParameterValue;
}
