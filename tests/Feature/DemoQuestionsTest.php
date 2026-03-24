<?php

test('questions demo page is not available', function () {
    $this->get('/questions')->assertNotFound();
});
