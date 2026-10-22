<?php
declare(strict_types=1);
require __DIR__ . '/config/bootstrap.php';

$maxBytes = (int)cfg('max_upload_bytes');
$maxMb = rtrim(rtrim(number_format($maxBytes / 1048576, 1), '0'), '.');
$jsConfig = [
    'maxBytes'       => $maxBytes,
    'maxWidth'       => (int)cfg('max_image_width'),
    'maxHeight'      => (int)cfg('max_image_height'),
    'timeoutSeconds' => (int)cfg('api_timeout_seconds'),
    'types'          => ['image/jpeg', 'image/png', 'image/webp'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>RemoveBG - Remove Image Backgrounds in Seconds</title>
    <meta name="description" content="Upload an image, let AI remove the background, and download a clean transparent PNG.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<a class="visually-hidden-focusable position-absolute p-2 bg-white" href="#tool">Skip to the tool</a>

<nav class="navbar navbar-expand-md bg-white border-bottom sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#top"><i class="fa-solid fa-wand-magic-sparkles text-primary me-2" aria-hidden="true"></i>RemoveBG</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                <li class="nav-item"><a class="nav-link" href="#how">How it works</a></li>
                <li class="nav-item"><a class="nav-link" href="#formats">Formats</a></li>
                <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                <li class="nav-item"><a class="btn btn-primary btn-sm" href="#tool">Upload image</a></li>
            </ul>
        </div>
    </div>
</nav>

<header class="hero" id="top">
    <div class="container text-center">
        <h1 class="display-5 fw-bold">Remove Image Backgrounds in Seconds</h1>
        <p class="lead text-secondary mx-auto hero-lead">Upload an image, let AI remove the background, and download a clean transparent image in just a few clicks.</p>
    </div>
</header>

<main>
<section id="tool" class="container tool-wrap" aria-labelledby="tool-heading">
    <h2 id="tool-heading" class="visually-hidden">Background remover</h2>
    <div class="card shadow-sm tool-card">
        <div class="card-body p-3 p-md-4">

            <div id="alert" class="alert alert-danger d-flex flex-wrap align-items-center gap-2" role="alert" hidden>
                <span id="alert-text" class="flex-grow-1"></span>
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn-retry" hidden><i class="fa-solid fa-rotate-right me-1" aria-hidden="true"></i>Retry</button>
            </div>

            <!-- 1. choose -->
            <div id="state-upload">
                <label id="dropzone" class="dropzone" for="file-input" role="button" tabindex="0" aria-describedby="dz-help">
                    <i class="fa-solid fa-cloud-arrow-up fa-2x text-primary mb-2" aria-hidden="true"></i>
                    <span class="d-block fw-semibold mb-1">Drag &amp; drop an image here</span>
                    <span class="d-block text-secondary mb-3">or</span>
                    <span class="btn btn-primary"><i class="fa-solid fa-image me-2" aria-hidden="true"></i>Upload Image</span>
                    <span id="dz-help" class="d-block small text-secondary mt-3 mb-0">JPG, PNG or WebP, up to <?= h($maxMb) ?> MB and <?= (int)cfg('max_image_width') ?> x <?= (int)cfg('max_image_height') ?> px</span>
                </label>
                <input type="file" id="file-input" class="visually-hidden" accept="image/jpeg,image/png,image/webp" aria-label="Choose an image file">
            </div>

            <!-- 2. preview + process -->
            <div id="state-preview" hidden>
                <div class="row g-3 align-items-center">
                    <div class="col-md-7 text-center">
                        <div class="preview-box"><img id="orig-preview" alt="Preview of your uploaded image"></div>
                    </div>
                    <div class="col-md-5">
                        <p class="mb-1 fw-semibold text-break" id="file-name"></p>
                        <p class="text-secondary small" id="file-meta"></p>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary btn-lg" id="btn-process"><i class="fa-solid fa-scissors me-2" aria-hidden="true"></i>Remove background</button>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary flex-fill" id="btn-replace"><i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i>Replace</button>
                                <button type="button" class="btn btn-outline-secondary flex-fill" id="btn-clear"><i class="fa-solid fa-xmark me-1" aria-hidden="true"></i>Clear</button>
                            </div>
                        </div>
                        <div id="status" class="status mt-3" role="status" aria-live="polite" hidden>
                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><span id="status-text"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. editor -->
            <div id="state-result" hidden>
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div id="stage" class="stage" aria-label="Before and after comparison">
                            <div class="layer layer-result checker" id="layer-result"><img id="result-img" alt="Image with the background removed"></div>
                            <div class="layer layer-original" id="layer-original"><img id="orig-compare" alt="Original image"></div>
                            <div class="cmp-line" id="cmp-line" aria-hidden="true"></div>
                        </div>
                        <div class="mt-3">
                            <label for="cmp-range" class="form-label small mb-1">Compare: <span class="text-secondary">original (left) vs. result (right)</span></label>
                            <input type="range" class="form-range" id="cmp-range" min="0" max="100" value="50">
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h3 class="h6 text-uppercase text-secondary">Background</h3>
                        <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Preview background">
                            <button type="button" class="btn btn-outline-secondary bg-btn active" data-bg="transparent" aria-pressed="true"><span class="swatch swatch-checker" aria-hidden="true"></span> Transparent</button>
                            <button type="button" class="btn btn-outline-secondary bg-btn" data-bg="#ffffff" aria-pressed="false"><span class="swatch" style="background:#fff" aria-hidden="true"></span> White</button>
                            <button type="button" class="btn btn-outline-secondary bg-btn" data-bg="#000000" aria-pressed="false"><span class="swatch" style="background:#000" aria-hidden="true"></span> Black</button>
                            <label class="btn btn-outline-secondary bg-btn mb-0" id="custom-wrap" for="custom-color"><span class="swatch swatch-rainbow" aria-hidden="true"></span> Custom
                                <input type="color" id="custom-color" value="#3b82f6" class="color-input" aria-label="Custom background colour"></label>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary" id="btn-dl-transparent"><i class="fa-solid fa-download me-2" aria-hidden="true"></i>Download transparent PNG</button>
                            <button type="button" class="btn btn-success" id="btn-dl-bg" disabled><i class="fa-solid fa-fill-drip me-2" aria-hidden="true"></i>Download with background</button>
                            <p class="small text-secondary mb-1" id="dl-hint">Pick White, Black or a custom colour to export with that background.</p>
                            <button type="button" class="btn btn-outline-secondary" id="btn-reset"><i class="fa-solid fa-rotate-left me-2" aria-hidden="true"></i>Reset preview</button>
                            <button type="button" class="btn btn-outline-primary" id="btn-another"><i class="fa-solid fa-plus me-2" aria-hidden="true"></i>Process another image</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<section id="features" class="container section">
    <h2 class="text-center fw-bold mb-4">Why RemoveBG</h2>
    <div class="row g-3">
        <?php
        $features = [
            ['fa-robot', 'Real AI cutouts', 'Every image is processed by a professional background-removal API, not a filter or a trick.'],
            ['fa-border-none', 'Transparent PNG', 'Download a clean cutout with its transparency intact.'],
            ['fa-palette', 'Any background colour', 'Preview on white, black or any colour, and export with that colour baked into the pixels.'],
            ['fa-user-slash', 'No sign-up', 'No account, no login, no history. Upload, edit, download.'],
            ['fa-lock', 'Private by design', 'Files are kept only briefly on the server and deleted automatically.'],
            ['fa-mobile-screen', 'Works everywhere', 'Responsive layout for desktop, tablet and phone.'],
        ];
        foreach ($features as [$icon, $title, $text]): ?>
        <div class="col-md-6 col-lg-4"><div class="card h-100 feature-card"><div class="card-body">
            <i class="fa-solid <?= h($icon) ?> text-primary fa-lg mb-2" aria-hidden="true"></i>
            <h3 class="h6 fw-bold"><?= h($title) ?></h3><p class="text-secondary mb-0"><?= h($text) ?></p>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</section>

<section id="how" class="section bg-white border-top border-bottom">
    <div class="container">
        <h2 class="text-center fw-bold mb-4">How it works</h2>
        <div class="row g-4 text-center">
            <?php
            $steps = ['Upload', 'Remove', 'Customise', 'Download'];
            $stepText = [
                'Drop in a JPG, PNG or WebP image.',
                'Press "Remove background" and wait a few seconds.',
                'Compare before and after, then choose a background.',
                'Save a transparent PNG or a version with your colour.',
            ];
            foreach ($steps as $i => $s): ?>
            <div class="col-6 col-lg-3"><div class="step-num"><?= $i + 1 ?></div><h3 class="h6 fw-bold mt-2"><?= h($s) ?></h3><p class="text-secondary small"><?= h($stepText[$i]) ?></p></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="formats" class="container section">
    <h2 class="text-center fw-bold mb-4">Formats &amp; limits</h2>
    <div class="row justify-content-center"><div class="col-lg-8">
        <ul class="list-group shadow-sm">
            <li class="list-group-item d-flex justify-content-between"><span>Accepted formats</span><strong>JPG, PNG, WebP</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>Maximum file size</span><strong><?= h($maxMb) ?> MB</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>Maximum dimensions</span><strong><?= (int)cfg('max_image_width') ?> x <?= (int)cfg('max_image_height') ?> px</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>Output</span><strong>Transparent PNG</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>Usage limit</span><strong><?= (int)cfg('rate_limit_max') ?> images per <?= max(1, (int)round((int)cfg('rate_limit_window_seconds') / 60)) ?> minutes</strong></li>
        </ul>
    </div></div>
</section>

<section id="faq" class="container section">
    <h2 class="text-center fw-bold mb-4">Frequently asked questions</h2>
    <div class="accordion mx-auto faq" id="faqAcc">
        <?php
        $faq = [
            ['Do I need an account?', 'No. RemoveBG is anonymous: there is no registration, login or processing history.'],
            ['Who processes my image?', 'Your image is sent securely from our server to a third-party background-removal service (Pixelcut). Its own terms and privacy policy apply to that processing.'],
            ['How long are my files kept?', 'The uploaded original is deleted as soon as processing ends. The result is deleted when your browser fetches it, or after ' . (int)cfg('temp_file_retention_minutes') . ' minutes at the latest.'],
            ['Why is my downloaded image the right colour?', 'When you export with a background colour, the colour is painted into the actual pixels of the file, not just shown on the page.'],
            ['Why did processing fail?', 'Common reasons: no clear subject in the image, an unsupported or corrupted file, or the service being busy. Use Retry or try another image.'],
        ];
        foreach ($faq as $i => [$q, $a]): ?>
        <div class="accordion-item">
            <h3 class="accordion-header"><button class="accordion-button <?= $i ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="faq<?= $i ?>"><?= h($q) ?></button></h3>
            <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i ? '' : 'show' ?>" data-bs-parent="#faqAcc"><div class="accordion-body"><?= h($a) ?></div></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
</main>

<footer class="border-top bg-white py-4">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2 small text-secondary">
        <span>&copy; <?= date('Y') ?> RemoveBG</span>
        <span><a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a> &middot; <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Service</a></span>
    </div>
</footer>

<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="privacyTitle">Privacy Policy</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <p>RemoveBG does not require an account and keeps no processing history.</p>
        <p>Images you upload are sent to a third-party background-removal service to produce the result. They are stored on our server only temporarily: the original is deleted when processing ends and the result is deleted once your browser retrieves it, or after <?= (int)cfg('temp_file_retention_minutes') ?> minutes.</p>
        <p>We keep short-lived rate-limit counters keyed by a one-way hash of your IP address, and technical error logs that do not contain image content. A session cookie is used for security (CSRF and download protection).</p>
        <p class="mb-0">Site owners: replace this text with your own legal policy before launch.</p>
    </div></div></div></div>
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="termsTitle">Terms of Service</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <p>Use RemoveBG only with images you have the right to edit. Do not upload unlawful content.</p>
        <p>The service is provided "as is" without warranty. Results depend on the image and the third-party AI service, and may not be perfect. Usage may be limited to protect availability.</p>
        <p class="mb-0">Site owners: replace this text with your own legal terms before launch.</p>
    </div></div></div></div>

<script id="rbg-config" type="application/json"><?= json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
