<?php
require_once 'config/config.php';
require_once 'includes/chat_helpers.php';
checkAuth();

// Clients never see internal team chat; the API enforces the same rule.
if (!in_array($_SESSION['role'] ?? '', TEAM_CHAT_ALLOWED_ROLES, true)) {
    header('Location: index.php');
    exit;
}

include 'includes/header.php';
?>
<style>
    .tc-scroll { overflow: hidden !important; padding-bottom: 0 !important; display: flex; flex-direction: column; height: 100vh; height: 100dvh; }
    .tc-shell {
        flex: 1; min-height: 0; display: flex; flex-direction: column;
        margin: 12px 12px calc(12px + env(safe-area-inset-bottom, 0px)); background: #fff; border: 1px solid var(--border-color);
        border-radius: 18px; box-shadow: 0 6px 24px rgba(15,23,42,.06); overflow: hidden;
    }
    @media (min-width: 992px) { .tc-shell { margin: 16px 0 16px; } }

    .tc-header { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border-color); background: linear-gradient(135deg, #0b1e46 0%, #1e3a8a 100%); color: #fff; }
    .tc-group-icon { width: 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,.14); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .tc-header-text { flex: 1; min-width: 0; }
    .tc-header-title { font-weight: 700; font-size: 16px; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tc-header-sub { font-size: 12px; opacity: .8; }
    .tc-header-btn { background: rgba(255,255,255,.14); color: #fff; border: 1px solid rgba(255,255,255,.2); border-radius: 10px; padding: 7px 12px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
    .tc-header-btn:hover, .tc-header-btn:focus-visible { background: rgba(255,255,255,.24); outline: none; }
    @media (max-width: 480px) { .tc-header-btn span { display: none; } .tc-header { gap: 8px; } }

    .tc-banner { display: none; padding: 8px 14px; font-size: 12.5px; font-weight: 600; text-align: center; }
    .tc-banner.show { display: block; }
    .tc-banner.warn { background: #FEF3C7; color: #92400E; }
    .tc-banner.err { background: #FEE2E2; color: #991B1B; }

    .tc-body { position: relative; flex: 1; min-height: 0; }
    .tc-messages { position: absolute; inset: 0; overflow-y: auto; padding: 14px 14px 8px; background: #F7F8FC; -webkit-overflow-scrolling: touch; }
    .tc-older { display: none; margin: 0 auto 10px; background: #fff; border: 1px solid var(--border-color); border-radius: 999px; padding: 6px 14px; font-size: 12.5px; font-weight: 600; color: #1e3a8a; }
    .tc-older.show { display: block; }
    .tc-day { text-align: center; margin: 14px 0 8px; }
    .tc-day span { background: #e2e8f0; color: #475569; font-size: 11.5px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
    .tc-unread-divider { display: flex; align-items: center; gap: 8px; margin: 10px 0; color: #dc2626; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .tc-unread-divider::before, .tc-unread-divider::after { content: ''; flex: 1; height: 1px; background: #fca5a5; }

    .tc-row { display: flex; align-items: flex-end; gap: 8px; margin-top: 10px; }
    .tc-row.cont { margin-top: 3px; }
    .tc-row.mine { flex-direction: row-reverse; }
    .tc-avatar { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; background: #1e3a8a; color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .tc-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .tc-row.cont .tc-avatar { visibility: hidden; height: 0; }
    .tc-row.mine .tc-avatar { display: none; }
    .tc-bubble { max-width: min(78%, 560px); background: #fff; border: 1px solid var(--border-color); border-radius: 16px 16px 16px 4px; padding: 8px 12px 6px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .tc-row.mine .tc-bubble { background: #1e3a8a; border-color: #1e3a8a; color: #fff; border-radius: 16px 16px 4px 16px; }
    .tc-sender { font-size: 12px; font-weight: 700; color: #1e3a8a; margin-bottom: 2px; }
    .tc-row.mine .tc-sender, .tc-row.cont .tc-sender { display: none; }
    .tc-text { font-size: 14px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: anywhere; }
    .tc-time { font-size: 10.5px; color: #94a3b8; text-align: right; margin-top: 3px; }
    .tc-row.mine .tc-time { color: rgba(255,255,255,.7); }

    .tc-att { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 12px; padding: 8px 10px; margin: 4px 0; color: var(--text-dark); min-width: 220px; }
    .tc-row.mine .tc-att { background: rgba(255,255,255,.12); border-color: rgba(255,255,255,.25); color: #fff; }
    .tc-att-icon { font-size: 26px; width: 30px; text-align: center; flex-shrink: 0; }
    .tc-att-meta { flex: 1; min-width: 0; }
    .tc-att-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tc-att-sub { font-size: 11px; opacity: .75; }
    .tc-att-dl { flex-shrink: 0; width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #1e3a8a; color: #fff; text-decoration: none; }
    .tc-row.mine .tc-att-dl { background: #fff; color: #1e3a8a; }
    .tc-att-img { display: block; max-width: 100%; max-height: 260px; border-radius: 10px; margin: 4px 0; cursor: zoom-in; background: #e2e8f0; min-height: 60px; }

    .tc-empty { text-align: center; color: #64748b; padding: 48px 20px; }
    .tc-empty i { font-size: 40px; color: #cbd5e1; margin-bottom: 10px; }
    .tc-new-pill { display: none; position: absolute; left: 50%; bottom: 12px; transform: translateX(-50%); background: #1e3a8a; color: #fff; border: none; border-radius: 999px; padding: 7px 14px; font-size: 12.5px; font-weight: 600; box-shadow: 0 6px 18px rgba(30,58,138,.35); z-index: 5; }
    .tc-new-pill.show { display: inline-flex; align-items: center; gap: 6px; }

    .tc-composer { border-top: 1px solid var(--border-color); padding: 10px 12px; background: #fff; }
    .tc-file-chip { display: none; align-items: center; gap: 10px; background: #EEF2FF; border: 1px solid #c7d2fe; border-radius: 12px; padding: 6px 10px; margin-bottom: 8px; font-size: 13px; }
    .tc-file-chip.show { display: flex; }
    .tc-file-chip img { width: 36px; height: 36px; object-fit: cover; border-radius: 6px; }
    .tc-file-chip .tc-chip-name { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; color: #1e3a8a; }
    .tc-chip-remove { border: none; background: none; color: #64748b; font-size: 16px; padding: 2px 6px; }
    .tc-progress { display: none; height: 4px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-bottom: 8px; }
    .tc-progress.show { display: block; }
    .tc-progress > div { height: 100%; width: 0; background: #2563EB; transition: width .15s; }
    .tc-input-row { display: flex; align-items: flex-end; gap: 8px; }
    .tc-icon-btn { width: 42px; height: 42px; border-radius: 12px; border: 1px solid var(--border-color); background: #fff; color: #1e3a8a; font-size: 17px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .tc-icon-btn:hover:not(:disabled) { background: #EEF2FF; }
    .tc-send { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
    .tc-send:hover:not(:disabled) { background: #0b1e46; }
    .tc-icon-btn:disabled { opacity: .5; cursor: not-allowed; }
    .tc-textarea { flex: 1; resize: none; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 12px; font-size: 14px; line-height: 1.4; max-height: 140px; min-height: 42px; font-family: inherit; }
    .tc-textarea:focus { outline: none; border-color: #2563EB; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
    .tc-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
    .tc-error { display: none; font-size: 12.5px; color: #b91c1c; margin-top: 6px; font-weight: 600; }
    .tc-error.show { display: block; }

    /* The global floating Notes button would sit on top of the composer /
       messages here, so on this page it opens from the chat header instead. */
    .fnotes-fab { display: none !important; }
    .fnotes-min-bar { top: 168px !important; bottom: auto !important; }

    /* Groups: side list on desktop, "Groups" button + sheet on mobile */
    .tc-shell { flex-direction: row; }
    .tc-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .tc-groups { display: none; width: 270px; flex-shrink: 0; border-right: 1px solid var(--border-color); background: #fff; flex-direction: column; min-height: 0; }
    @media (min-width: 992px) { .tc-groups { display: flex; } .tc-groups-btn { display: none !important; } }
    .tc-groups-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px 14px 10px; }
    .tc-groups-title { font-size: 15px; font-weight: 800; color: var(--text-dark); margin: 0; }
    .tc-new-group { border: none; border-radius: 10px; padding: 7px 12px; font-size: 12.5px; font-weight: 700; color: #fff; background: linear-gradient(120deg, #1e3a8a, #4B2FD6); display: inline-flex; align-items: center; gap: 6px; }
    .tc-new-group:hover { filter: brightness(1.08); }
    .tc-group-list { flex: 1; min-height: 0; overflow-y: auto; padding: 0 8px 10px; }
    .tc-group-item { width: 100%; display: flex; align-items: center; gap: 10px; padding: 9px 10px; border: none; background: none; border-radius: 12px; text-align: left; color: var(--text-dark); text-decoration: none; }
    .tc-group-item:hover { background: #F1F5F9; color: var(--text-dark); }
    .tc-group-item.active { background: #EEF2FF; }
    .tc-group-item .gi-icon { width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0; background: #1e3a8a; color: #fff; font-weight: 800; font-size: 15px; display: flex; align-items: center; justify-content: center; }
    .tc-group-item.not-member .gi-icon { background: #CBD5E1; }
    .tc-group-item .gi-meta { flex: 1; min-width: 0; }
    .tc-group-item .gi-name { font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tc-group-item .gi-sub { font-size: 11.5px; color: #64748b; }
    .tc-group-item .gi-badge { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
    .tc-groups-empty { color: #64748b; font-size: 13px; padding: 12px; text-align: center; }
    .tc-groups-btn { position: relative; }
    .tc-groups-btn .gb-dot { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 10.5px; font-weight: 800; display: none; align-items: center; justify-content: center; border: 2px solid #1e3a8a; }
    .tc-groups-btn .gb-dot.show { display: flex; }
    .tc-staff-search { margin: 6px 0 8px; }
    .tc-staff-list { max-height: 300px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 12px; padding: 4px 10px; }
    .tc-staff-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; cursor: pointer; margin: 0; }
    .tc-staff-row:last-child { border-bottom: none; }
    .tc-staff-row input { width: 17px; height: 17px; flex-shrink: 0; }

    .tc-loading { display: flex; align-items: center; justify-content: center; height: 100%; color: #64748b; gap: 10px; font-size: 14px; }

    .swal2-container { z-index: 2100 !important; }
    .tc-dialog-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 16px; }
    .tc-dialog-backdrop.show { display: flex; }
    .tc-dialog { background: #fff; border-radius: 18px; width: 100%; max-width: 460px; max-height: 86vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(15,23,42,.25); }
    .tc-dialog-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border-color); }
    .tc-dialog-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-dark); }
    .tc-dialog-body { padding: 12px 18px 18px; overflow-y: auto; }
    .tc-member { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
    .tc-member:last-child { border-bottom: none; }
    .tc-member-name { font-weight: 600; font-size: 14px; color: var(--text-dark); }
    .tc-member-role { font-size: 12px; color: #64748b; }
    .tc-member-remove { margin-left: auto; border: 1px solid #fecaca; color: #b91c1c; background: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; padding: 5px 10px; }
    .tc-add-row { display: flex; gap: 8px; margin-top: 8px; }
    .tc-add-row select { flex: 1; min-width: 0; }
    .tc-section-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin: 10px 0 4px; }
</style>

<div class="scroll-content tc-scroll">
    <?php $page_title = 'Team Chat'; $back_url = 'index.php'; $page_testid = 'team-chat'; include 'includes/top_app_bar.php'; ?>

    <section class="tc-shell" id="tcApp"
             data-csrf="<?= sanitize(csrf_token()) ?>"
             data-me="<?= (int)$_SESSION['user_id'] ?>"
             aria-label="Team chat" data-testid="team-chat-app">
        <aside class="tc-groups" aria-label="Chat groups" data-testid="team-chat-groups-pane">
            <div class="tc-groups-head">
                <h2 class="tc-groups-title">Groups</h2>
                <button type="button" class="tc-new-group" data-new-group hidden data-testid="team-chat-new-group-button"><i class="fa-solid fa-plus" aria-hidden="true"></i> New Group</button>
            </div>
            <div class="tc-group-list" data-group-list></div>
        </aside>
        <div class="tc-main">
        <header class="tc-header">
            <div class="tc-group-icon" aria-hidden="true"><i class="fa-solid fa-users"></i></div>
            <div class="tc-header-text">
                <h2 class="tc-header-title" id="tcGroupName">Team Chat</h2>
                <div class="tc-header-sub" id="tcMemberCount" aria-live="polite">Loading…</div>
            </div>
            <button type="button" class="tc-header-btn tc-groups-btn" id="tcGroupsBtn" aria-label="Groups" title="Groups" data-testid="team-chat-groups-button">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i><span>Groups</span><span class="gb-dot" id="tcGroupsDot"></span>
            </button>
            <button type="button" class="tc-header-btn" id="tcNotesBtn" hidden aria-label="Open Notes" title="Notes" data-testid="team-chat-notes-button">
                <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>
            </button>
            <button type="button" class="tc-header-btn" id="tcMembersBtn" hidden aria-label="Group members" data-testid="team-chat-members-button">
                <i class="fa-solid fa-user-group" aria-hidden="true"></i><span>Members</span>
            </button>
        </header>

        <div class="tc-banner" id="tcBanner" role="status" aria-live="polite"></div>

        <div class="tc-body">
            <div class="tc-messages" id="tcMessages" role="log" aria-live="polite" aria-label="Messages" tabindex="0">
                <div class="tc-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading conversation…</div>
            </div>
            <button type="button" class="tc-new-pill" id="tcNewPill" data-testid="team-chat-new-pill"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i><span></span></button>
        </div>

        <form class="tc-composer" id="tcComposer" hidden autocomplete="off" data-testid="team-chat-composer">
            <div class="tc-file-chip" id="tcFileChip">
                <i class="fa-solid fa-paperclip" aria-hidden="true" id="tcChipIcon"></i>
                <img id="tcChipPreview" alt="" hidden>
                <span class="tc-chip-name" id="tcChipName"></span>
                <span class="text-muted small" id="tcChipSize"></span>
                <button type="button" class="tc-chip-remove" id="tcChipRemove" aria-label="Remove attachment"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="tc-progress" id="tcProgress" aria-hidden="true"><div></div></div>
            <div class="tc-input-row">
                <input type="file" id="tcFile" hidden accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.csv">
                <button type="button" class="tc-icon-btn" id="tcAttachBtn" aria-label="Attach a file" title="Attach a file" data-testid="team-chat-attach-button"><i class="fa-solid fa-paperclip"></i></button>
                <label for="tcInput" class="visually-hidden">Message</label>
                <textarea class="tc-textarea" id="tcInput" rows="1" placeholder="Type a message…" data-testid="team-chat-input"></textarea>
                <button type="submit" class="tc-icon-btn tc-send" id="tcSendBtn" aria-label="Send message" title="Send" data-testid="team-chat-send-button"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
            <div class="tc-hint d-none d-md-block">Enter to send · Shift+Enter for a new line · PDF, Word, Excel, JPG, PNG, TXT, CSV</div>
            <div class="tc-error" id="tcError" role="alert"></div>
        </form>
        </div>
    </section>
</div>

<div class="tc-dialog-backdrop" id="tcGroupsDialog" aria-hidden="true">
    <div class="tc-dialog" role="dialog" aria-modal="true" aria-labelledby="tcGroupsDialogTitle">
        <div class="tc-dialog-head">
            <h2 id="tcGroupsDialogTitle">Groups</h2>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="tc-new-group" data-new-group hidden data-testid="team-chat-new-group-button-mobile"><i class="fa-solid fa-plus" aria-hidden="true"></i> New</button>
                <button type="button" class="btn-close" data-close-dialog aria-label="Close"></button>
            </div>
        </div>
        <div class="tc-dialog-body"><div data-group-list></div></div>
    </div>
</div>

<div class="tc-dialog-backdrop" id="tcCreateDialog" aria-hidden="true">
    <div class="tc-dialog" role="dialog" aria-modal="true" aria-labelledby="tcCreateTitle">
        <div class="tc-dialog-head">
            <h2 id="tcCreateTitle">New Group</h2>
            <button type="button" class="btn-close" data-close-dialog aria-label="Close"></button>
        </div>
        <form class="tc-dialog-body" id="tcCreateForm" autocomplete="off" novalidate data-testid="team-chat-create-form">
            <label class="tc-section-label" for="tcNewName">Group name</label>
            <input type="text" class="form-control" id="tcNewName" maxlength="60" placeholder="e.g. Kakinada Surveyors" data-testid="team-chat-new-group-name">
            <div class="tc-section-label mt-3">Add members <span class="text-muted fw-normal text-lowercase" id="tcPickedCount"></span></div>
            <div class="small text-muted">You are added automatically. You can add or remove members later too.</div>
            <input type="search" class="form-control form-control-sm tc-staff-search" id="tcStaffSearch" placeholder="Search staff…" aria-label="Search staff">
            <div class="tc-staff-list" id="tcStaffList" data-testid="team-chat-staff-list"></div>
            <div class="tc-error" id="tcCreateError" role="alert"></div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary flex-fill" id="tcCreateBtn" style="background:#1e3a8a;border-color:#1e3a8a;font-weight:700;" data-testid="team-chat-create-group-submit">Create Group</button>
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="tc-dialog-backdrop" id="tcDialog" aria-hidden="true">
    <div class="tc-dialog" role="dialog" aria-modal="true" aria-labelledby="tcDialogTitle">
        <div class="tc-dialog-head">
            <h2 id="tcDialogTitle">Group Members</h2>
            <button type="button" class="btn-close" id="tcDialogClose" aria-label="Close"></button>
        </div>
        <div class="tc-dialog-body">
            <div id="tcManageBox" hidden>
                <div class="tc-section-label">Add a member</div>
                <div class="tc-add-row">
                    <label for="tcCandidate" class="visually-hidden">Select a user</label>
                    <select class="form-select form-select-sm" id="tcCandidate" data-testid="team-chat-candidate-select"></select>
                    <button type="button" class="btn btn-sm btn-primary" id="tcAddBtn" style="background:#1e3a8a;border-color:#1e3a8a;" data-testid="team-chat-add-member-button">Add</button>
                </div>
                <div class="tc-error" id="tcManageError" role="alert"></div>
            </div>
            <div class="tc-section-label">Current members</div>
            <div id="tcMemberList"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    'use strict';
    var API = 'ajax/team_chat.php';
    var app = document.getElementById('tcApp');
    var CSRF = app.getAttribute('data-csrf');
    var ME = parseInt(app.getAttribute('data-me'), 10);
    var $ = function (id) { return document.getElementById(id); };
    var els = {
        name: $('tcGroupName'), count: $('tcMemberCount'), membersBtn: $('tcMembersBtn'),
        banner: $('tcBanner'), list: $('tcMessages'), pill: $('tcNewPill'),
        form: $('tcComposer'), input: $('tcInput'), send: $('tcSendBtn'), attach: $('tcAttachBtn'),
        file: $('tcFile'), chip: $('tcFileChip'), chipName: $('tcChipName'), chipSize: $('tcChipSize'),
        chipPreview: $('tcChipPreview'), chipIcon: $('tcChipIcon'), chipRemove: $('tcChipRemove'),
        progress: $('tcProgress'), error: $('tcError'),
        dialog: $('tcDialog'), dialogClose: $('tcDialogClose'), memberList: $('tcMemberList'),
        manageBox: $('tcManageBox'), candidate: $('tcCandidate'), addBtn: $('tcAddBtn'), manageError: $('tcManageError')
    };

    var REQ_GROUP = parseInt(new URLSearchParams(location.search).get('group') || '0', 10) || 0;
    var S = {
        groups: [],
        groupId: 0, canManage: false, isMember: false, settings: null,
        messages: [], ids: new Set(), avatars: {}, members: [],
        lastId: 0, hasMore: false, loadingOlder: false,
        lastReadAtLoad: 0, markedId: 0, unseen: 0,
        pollTimer: null, polling: false, failures: 0, stopped: false,
        sending: false, pendingToken: null, file: null, previewUrl: null,
        baseTitle: document.title
    };
    var POLL_VISIBLE = 4000, POLL_HIDDEN = 15000;

    // ------------------------------------------------------------ helpers
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b);
        b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
        var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }
    function fmtTime(ts) { return new Date(ts * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
    function fmtDateTime(ts) {
        return new Date(ts * 1000).toLocaleString([], { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    function dayKey(ts) { var d = new Date(ts * 1000); return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
    function dayLabel(ts) {
        var d = new Date(ts * 1000), now = new Date();
        var y = new Date(now); y.setDate(now.getDate() - 1);
        if (dayKey(ts) === dayKey(now.getTime() / 1000)) return 'Today';
        if (dayKey(ts) === dayKey(y.getTime() / 1000)) return 'Yesterday';
        return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    }
    function initials(name) { return (String(name || '?').trim().charAt(0) || '?').toUpperCase(); }
    function fileIcon(name, mime) {
        var ext = String(name).split('.').pop().toLowerCase();
        if (mime === 'image/jpeg' || mime === 'image/png') return 'fa-file-image';
        if (ext === 'pdf') return 'fa-file-pdf';
        if (ext === 'doc' || ext === 'docx') return 'fa-file-word';
        if (ext === 'xls' || ext === 'xlsx') return 'fa-file-excel';
        if (ext === 'csv') return 'fa-file-csv';
        return 'fa-file-lines';
    }
    function iconColor(icon) {
        return { 'fa-file-pdf': '#dc2626', 'fa-file-word': '#2563eb', 'fa-file-excel': '#16a34a', 'fa-file-csv': '#16a34a', 'fa-file-image': '#7c3aed' }[icon] || '#64748b';
    }
    function showBanner(text, kind) {
        if (!text) { els.banner.className = 'tc-banner'; els.banner.textContent = ''; return; }
        els.banner.className = 'tc-banner show ' + (kind || 'warn');
        els.banner.textContent = text;
    }
    function showError(node, text) { node.textContent = text || ''; node.classList.toggle('show', !!text); }
    function nearBottom() { var l = els.list; return l.scrollHeight - l.scrollTop - l.clientHeight < 80; }
    function scrollBottom() { els.list.scrollTop = els.list.scrollHeight; }

    function request(method, params, body, onProgress) {
        return new Promise(function (resolve) {
            var xhr = new XMLHttpRequest();
            var url = API + (method === 'GET' ? '?' + new URLSearchParams(params).toString() : '');
            xhr.open(method, url, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.timeout = body instanceof FormData && body.has('file') ? 300000 : 20000;
            if (onProgress && xhr.upload) {
                xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
            }
            xhr.onload = function () {
                var data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) {}
                if (!data) data = { ok: false, error: 'Unexpected server response. Please try again.', code: 'bad_response' };
                resolve({ status: xhr.status, data: data });
            };
            xhr.onerror = function () { resolve({ status: 0, data: { ok: false, error: 'Network error. Check your connection and try again.', code: 'network' } }); };
            xhr.ontimeout = function () { resolve({ status: 0, data: { ok: false, error: 'The request timed out. Please try again.', code: 'timeout' } }); };
            xhr.send(body || null);
        });
    }
    function post(fields, onProgress) {
        var fd = fields instanceof FormData ? fields : new FormData();
        if (!(fields instanceof FormData)) Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append('csrf_token', CSRF);
        return request('POST', null, fd, onProgress);
    }
    function handleAuthFailure(res) {
        if (res.status === 401) {
            stopPolling();
            showBanner('Your session has expired. Please log in again.', 'err');
            setComposerEnabled(false);
            return true;
        }
        if (res.status === 403 && (res.data.code === 'not_member' || res.data.code === 'forbidden')) {
            stopPolling();
            S.isMember = false;
            showBanner('You no longer have access to this group.', 'err');
            els.form.hidden = true;
            renderNotMember();
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------ rendering
    function buildAttachment(a, msg, mine) {
        var frag = document.createDocumentFragment();
        var href = API + '?action=download&id=' + encodeURIComponent(a.id);
        if (a.is_image) {
            var link = el('a');
            link.href = href + '&inline=1';
            link.target = '_blank';
            link.rel = 'noopener';
            var img = el('img', 'tc-att-img');
            img.loading = 'lazy';
            img.alt = a.name;
            img.src = href + '&inline=1';
            link.appendChild(img);
            frag.appendChild(link);
        }
        var card = el('div', 'tc-att');
        var icon = fileIcon(a.name, a.mime);
        var ic = el('i', 'fa-solid ' + icon + ' tc-att-icon');
        ic.setAttribute('aria-hidden', 'true');
        if (!mine) ic.style.color = iconColor(icon);
        var meta = el('div', 'tc-att-meta');
        var nm = el('div', 'tc-att-name', a.name); nm.title = a.name;
        meta.appendChild(nm);
        meta.appendChild(el('div', 'tc-att-sub', a.type_label + ' · ' + fmtSize(a.size)));
        meta.appendChild(el('div', 'tc-att-sub', 'By ' + msg.sender_name + ' · ' + fmtDateTime(a.created_ts)));
        var dl = el('a', 'tc-att-dl');
        dl.href = href;
        dl.setAttribute('download', '');
        dl.setAttribute('aria-label', 'Download ' + a.name);
        dl.title = 'Download';
        var di = el('i', 'fa-solid fa-download'); di.setAttribute('aria-hidden', 'true');
        dl.appendChild(di);
        card.appendChild(ic); card.appendChild(meta); card.appendChild(dl);
        frag.appendChild(card);
        return frag;
    }

    function buildRow(m, prev) {
        var mine = m.sender_id === ME;
        var cont = prev && prev.sender_id === m.sender_id && dayKey(prev.ts) === dayKey(m.ts) && (m.ts - prev.ts) < 300;
        var row = el('div', 'tc-row' + (mine ? ' mine' : '') + (cont ? ' cont' : ''));
        row.setAttribute('data-id', m.id);
        var av = el('div', 'tc-avatar');
        var avatarUrl = S.avatars[String(m.sender_id)];
        if (avatarUrl) { var im = el('img'); im.src = avatarUrl; im.alt = ''; av.appendChild(im); }
        else av.textContent = initials(m.sender_name);
        av.setAttribute('aria-hidden', 'true');
        var bub = el('div', 'tc-bubble');
        bub.appendChild(el('div', 'tc-sender', m.sender_name));
        (m.attachments || []).forEach(function (a) { bub.appendChild(buildAttachment(a, m, mine)); });
        if (m.text) bub.appendChild(el('div', 'tc-text', m.text));
        var t = el('div', 'tc-time', fmtTime(m.ts));
        t.title = fmtDateTime(m.ts);
        var sr = el('span', 'visually-hidden', (mine ? 'You' : m.sender_name) + ', ' + fmtDateTime(m.ts) + ': ');
        bub.insertBefore(sr, bub.firstChild);
        bub.appendChild(t);
        row.appendChild(av); row.appendChild(bub);
        return row;
    }

    function dividerFor(m, prev) {
        if (!prev || dayKey(prev.ts) !== dayKey(m.ts)) {
            var d = el('div', 'tc-day'); d.appendChild(el('span', null, dayLabel(m.ts)));
            return d;
        }
        return null;
    }

    function renderAll(unreadFromId) {
        var l = els.list;
        l.textContent = '';
        var older = el('button', 'tc-older' + (S.hasMore ? ' show' : ''), 'Load older messages');
        older.type = 'button'; older.id = 'tcOlderBtn';
        older.addEventListener('click', loadOlder);
        l.appendChild(older);
        if (!S.messages.length) {
            var empty = el('div', 'tc-empty');
            var i = el('i', 'fa-regular fa-comments d-block'); i.setAttribute('aria-hidden', 'true');
            empty.appendChild(i);
            empty.appendChild(el('div', 'fw-semibold text-dark', 'No messages yet'));
            empty.appendChild(el('div', 'small', 'Start the conversation with your team.'));
            empty.id = 'tcEmpty';
            l.appendChild(empty);
            return null;
        }
        var prev = null, unreadNode = null;
        S.messages.forEach(function (m) {
            var d = dividerFor(m, prev); if (d) { l.appendChild(d); prev = null; }
            if (unreadFromId && !unreadNode && m.id >= unreadFromId) {
                unreadNode = el('div', 'tc-unread-divider', 'New messages');
                l.appendChild(unreadNode);
                prev = null;
            }
            l.appendChild(buildRow(m, prev));
            prev = m;
        });
        return unreadNode;
    }

    function appendMessages(list) {
        var fresh = list.filter(function (m) { return !S.ids.has(m.id); });
        if (!fresh.length) return [];
        var empty = $('tcEmpty'); if (empty) empty.remove();
        var stick = nearBottom();
        fresh.forEach(function (m) {
            var prev = S.messages[S.messages.length - 1] || null;
            var d = dividerFor(m, prev); if (d) { els.list.appendChild(d); }
            els.list.appendChild(buildRow(m, d ? null : prev));
            S.messages.push(m); S.ids.add(m.id);
            if (m.id > S.lastId) S.lastId = m.id;
        });
        var fromOthers = fresh.filter(function (m) { return m.sender_id !== ME; }).length;
        if (stick || !fromOthers) scrollBottom();
        if (fromOthers && (!stick || document.hidden)) S.unseen += fromOthers;
        updateUnseen();
        return fresh;
    }

    function updateUnseen() {
        if (nearBottom() && !document.hidden) S.unseen = 0;
        if (S.unseen > 0) {
            els.pill.querySelector('span').textContent = S.unseen + ' new message' + (S.unseen === 1 ? '' : 's');
            els.pill.classList.add('show');
            document.title = '(' + S.unseen + ') ' + S.baseTitle;
        } else {
            els.pill.classList.remove('show');
            document.title = S.baseTitle;
        }
        maybeMarkRead();
    }

    var markTimer = null;
    function maybeMarkRead() {
        if (!S.isMember || document.hidden || !nearBottom() || S.lastId <= S.markedId) return;
        clearTimeout(markTimer);
        markTimer = setTimeout(function () {
            var id = S.lastId;
            if (id <= S.markedId) return;
            S.markedId = id;
            post({ action: 'mark_read', group_id: S.groupId, message_id: id }).then(function (res) {
                if (res.data.ok) {
                    var badge = document.getElementById('tcNavBadge');
                    if (badge) badge.remove();
                } else if (!handleAuthFailure(res)) {
                    S.markedId = Math.min(S.markedId, id - 1);
                }
            });
        }, 600);
    }

    function setMemberCount(n) { els.count.textContent = n + ' member' + (n === 1 ? '' : 's'); }

    function renderNotMember() {
        els.list.textContent = '';
        var box = el('div', 'tc-empty');
        var i = el('i', 'fa-solid fa-lock d-block'); i.setAttribute('aria-hidden', 'true');
        box.appendChild(i);
        box.appendChild(el('div', 'fw-semibold text-dark', 'You are not a member of this group'));
        box.appendChild(el('div', 'small', S.canManage
            ? 'Use the Members button to add people (including yourself) to the group.'
            : 'Ask an administrator to add you to the group.'));
        els.list.appendChild(box);
    }

    // ------------------------------------------------------------ data flow
    function boot() {
        request('GET', REQ_GROUP ? { action: 'bootstrap', group_id: REQ_GROUP } : { action: 'bootstrap' }).then(function (res) {
            if (!res.data.ok) {
                if (!handleAuthFailure(res)) {
                    els.list.textContent = '';
                    var box = el('div', 'tc-empty');
                    box.appendChild(el('div', 'fw-semibold text-dark', res.data.error || 'Chat could not be loaded.'));
                    var retry = el('button', 'btn btn-sm btn-outline-primary mt-2', 'Retry');
                    retry.type = 'button';
                    retry.addEventListener('click', function () { location.reload(); });
                    box.appendChild(retry);
                    els.list.appendChild(box);
                }
                return;
            }
            var d = res.data;
            S.groupId = d.group.id; S.canManage = d.me.can_manage; S.isMember = d.me.is_member;
            S.settings = d.settings; S.members = d.members || [];
            S.lastReadAtLoad = d.last_read_message_id || 0; S.markedId = S.lastReadAtLoad;
            els.name.textContent = d.group.name;
            S.groups = d.groups || [];
            renderGroups();
            document.querySelectorAll('[data-new-group]').forEach(function (b) { b.hidden = !S.canManage; });
            if (history.replaceState) history.replaceState(null, '', 'team_chat.php?group=' + S.groupId);
            setInterval(refreshGroups, 15000);
            setMemberCount(S.members.length);
            els.membersBtn.hidden = !(S.isMember || S.canManage);
            if (S.settings && S.settings.max_text) els.input.maxLength = S.settings.max_text;
            if (!S.isMember) { renderNotMember(); return; }
            els.form.hidden = false;
            loadLatest();
        });
    }

    function loadLatest() {
        request('GET', { action: 'messages', group_id: S.groupId }).then(function (res) {
            if (!res.data.ok) {
                if (!handleAuthFailure(res)) showBanner(res.data.error || 'Messages could not be loaded.', 'err');
                schedulePoll(POLL_VISIBLE);
                return;
            }
            Object.assign(S.avatars, res.data.avatars || {});
            S.messages = res.data.messages; S.hasMore = res.data.has_more;
            S.ids = new Set(S.messages.map(function (m) { return m.id; }));
            S.lastId = S.messages.length ? S.messages[S.messages.length - 1].id : 0;
            var firstUnread = null;
            for (var i = 0; i < S.messages.length; i++) {
                if (S.messages[i].id > S.lastReadAtLoad && S.messages[i].sender_id !== ME) { firstUnread = S.messages[i].id; break; }
            }
            var divider = renderAll(firstUnread);
            if (divider) els.list.scrollTop = Math.max(0, divider.offsetTop - 40);
            else scrollBottom();
            updateUnseen();
            schedulePoll(POLL_VISIBLE);
            els.input.focus({ preventScroll: true });
        });
    }

    function loadOlder() {
        if (S.loadingOlder || !S.hasMore || !S.messages.length) return;
        S.loadingOlder = true;
        var btn = $('tcOlderBtn'); if (btn) btn.textContent = 'Loading…';
        request('GET', { action: 'messages', group_id: S.groupId, before_id: S.messages[0].id }).then(function (res) {
            S.loadingOlder = false;
            if (!res.data.ok) {
                if (btn) btn.textContent = 'Load older messages';
                if (!handleAuthFailure(res)) showBanner(res.data.error || 'Older messages could not be loaded.', 'err');
                return;
            }
            Object.assign(S.avatars, res.data.avatars || {});
            var older = res.data.messages.filter(function (m) { return !S.ids.has(m.id); });
            var before = els.list.scrollHeight, top = els.list.scrollTop;
            S.messages = older.concat(S.messages);
            older.forEach(function (m) { S.ids.add(m.id); });
            S.hasMore = res.data.has_more;
            renderAll(null);
            els.list.scrollTop = els.list.scrollHeight - before + top;
        });
    }

    function schedulePoll(delay) {
        if (S.stopped) return;
        clearTimeout(S.pollTimer);
        S.pollTimer = setTimeout(poll, delay);
    }
    function stopPolling() { S.stopped = true; clearTimeout(S.pollTimer); }

    function poll() {
        if (S.stopped || S.polling || !S.isMember) return;
        S.polling = true;
        request('GET', { action: 'poll', group_id: S.groupId, after_id: S.lastId }).then(function (res) {
            S.polling = false;
            if (res.data.ok) {
                if (S.failures) showBanner('');
                S.failures = 0;
                Object.assign(S.avatars, res.data.avatars || {});
                appendMessages(res.data.messages || []);
                if (typeof res.data.member_count === 'number') setMemberCount(res.data.member_count);
                schedulePoll(res.data.more ? 300 : (document.hidden ? POLL_HIDDEN : POLL_VISIBLE));
                return;
            }
            if (handleAuthFailure(res)) return;
            if (res.status === 429) { schedulePoll(POLL_VISIBLE); return; }
            S.failures++;
            if (S.failures >= 2) showBanner('Connection problem — retrying…', 'warn');
            schedulePoll(Math.min(30000, POLL_VISIBLE * Math.pow(2, S.failures)));
        });
    }

    // ------------------------------------------------------------ composer
    function setComposerEnabled(on) {
        els.input.disabled = !on; els.send.disabled = !on; els.attach.disabled = !on;
    }
    function autoGrow() {
        els.input.style.height = 'auto';
        els.input.style.height = Math.min(els.input.scrollHeight, 140) + 'px';
    }
    function clearFile() {
        S.file = null; els.file.value = '';
        if (S.previewUrl) { URL.revokeObjectURL(S.previewUrl); S.previewUrl = null; }
        els.chip.classList.remove('show'); els.chipPreview.hidden = true; els.chipIcon.hidden = false;
    }
    function pickFile(f) {
        showError(els.error, '');
        if (!f) return;
        var ext = f.name.split('.').pop().toLowerCase();
        var allowed = (S.settings && S.settings.allowed_ext) || [];
        if (allowed.indexOf(ext) === -1) { showError(els.error, 'Unsupported file type. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, TXT, CSV.'); els.file.value = ''; return; }
        var max = (S.settings && S.settings.max_upload_bytes) || 0;
        if (max && f.size > max) { showError(els.error, 'File is too large. Maximum size is ' + Math.round(max / 1048576) + ' MB.'); els.file.value = ''; return; }
        if (f.size === 0) { showError(els.error, 'The file is empty.'); els.file.value = ''; return; }
        clearFile();
        S.file = f; S.pendingToken = null;
        els.chipName.textContent = f.name; els.chipSize.textContent = fmtSize(f.size);
        if (ext === 'jpg' || ext === 'jpeg' || ext === 'png') {
            S.previewUrl = URL.createObjectURL(f);
            els.chipPreview.src = S.previewUrl; els.chipPreview.hidden = false; els.chipIcon.hidden = true;
        }
        els.chip.classList.add('show');
        els.input.focus();
    }

    function sendMessage() {
        if (S.sending || !S.isMember) return;
        var text = els.input.value.replace(/\s+$/, '');
        if (!text.trim() && !S.file) return;
        showError(els.error, '');
        S.sending = true;
        setComposerEnabled(false);
        if (!S.pendingToken) S.pendingToken = uuid();
        var fd = new FormData();
        fd.append('action', 'send');
        fd.append('group_id', S.groupId);
        fd.append('text', text);
        fd.append('client_token', S.pendingToken);
        var bar = els.progress.firstElementChild;
        if (S.file) {
            fd.append('file', S.file, S.file.name);
            els.progress.classList.add('show'); bar.style.width = '0';
        }
        post(fd, S.file ? function (p) { bar.style.width = Math.round(p * 100) + '%'; } : null).then(function (res) {
            S.sending = false;
            setComposerEnabled(true);
            els.progress.classList.remove('show');
            if (res.data.ok && res.data.message) {
                Object.assign(S.avatars, res.data.avatars || {});
                els.input.value = ''; autoGrow(); clearFile();
                S.pendingToken = null;
                appendMessages([res.data.message]);
                scrollBottom();
                S.unseen = 0; updateUnseen();
                els.input.focus();
                return;
            }
            if (handleAuthFailure(res)) return;
            if (res.data.code !== 'network' && res.data.code !== 'timeout') S.pendingToken = null;
            showError(els.error, res.data.error || 'Message could not be sent. Please try again.');
            els.input.focus();
        });
    }

    els.form.addEventListener('submit', function (e) { e.preventDefault(); sendMessage(); });
    els.input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            sendMessage();
        }
    });
    els.input.addEventListener('input', function () { autoGrow(); if (els.error.classList.contains('show')) showError(els.error, ''); });
    els.attach.addEventListener('click', function () { els.file.click(); });
    els.file.addEventListener('change', function () { pickFile(els.file.files && els.file.files[0]); });
    els.chipRemove.addEventListener('click', function () { clearFile(); els.input.focus(); });

    els.list.addEventListener('scroll', function () {
        if (els.list.scrollTop < 40 && S.hasMore && !S.loadingOlder) loadOlder();
        if (S.unseen && nearBottom()) updateUnseen(); else maybeMarkRead();
    });
    els.pill.addEventListener('click', function () { scrollBottom(); S.unseen = 0; updateUnseen(); });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { updateUnseen(); if (S.isMember && !S.stopped) schedulePoll(50); }
    });

    // ------------------------------------------------------------ members dialog
    var lastFocus = null;
    function openDialog() {
        lastFocus = document.activeElement;
        els.dialog.classList.add('show'); els.dialog.setAttribute('aria-hidden', 'false');
        els.manageBox.hidden = !S.canManage;
        showError(els.manageError, '');
        renderMembers();
        if (S.canManage) loadCandidates();
        els.dialogClose.focus();
    }
    function closeDialog() {
        els.dialog.classList.remove('show'); els.dialog.setAttribute('aria-hidden', 'true');
        if (lastFocus) lastFocus.focus();
    }
    function renderMembers() {
        els.memberList.textContent = '';
        if (!S.members.length) { els.memberList.appendChild(el('div', 'text-muted small py-2', 'No members yet.')); return; }
        S.members.forEach(function (m) {
            var row = el('div', 'tc-member');
            var av = el('div', 'tc-avatar');
            if (m.avatar) { var im = el('img'); im.src = m.avatar; im.alt = ''; av.appendChild(im); } else av.textContent = initials(m.name);
            av.setAttribute('aria-hidden', 'true');
            var meta = el('div');
            meta.appendChild(el('div', 'tc-member-name', m.name + (m.user_id === ME ? ' (You)' : '')));
            meta.appendChild(el('div', 'tc-member-role', m.role));
            row.appendChild(av); row.appendChild(meta);
            if (S.canManage) {
                var rm = el('button', 'tc-member-remove', 'Remove');
                rm.type = 'button';
                rm.setAttribute('aria-label', 'Remove ' + m.name + ' from the group');
                rm.addEventListener('click', function () { confirmRemove(m); });
                row.appendChild(rm);
            }
            els.memberList.appendChild(row);
        });
    }
    function loadCandidates() {
        els.candidate.textContent = '';
        els.candidate.appendChild(new Option('Loading users…', ''));
        els.addBtn.disabled = true;
        request('GET', { action: 'candidates', group_id: S.groupId }).then(function (res) {
            els.candidate.textContent = '';
            if (!res.data.ok) { els.candidate.appendChild(new Option('Could not load users', '')); showError(els.manageError, res.data.error); return; }
            var list = res.data.candidates || [];
            els.candidate.appendChild(new Option(list.length ? 'Select a user…' : 'All staff are already members', ''));
            list.forEach(function (u) { els.candidate.appendChild(new Option(u.name + ' — ' + u.role, String(u.user_id))); });
            els.addBtn.disabled = !list.length;
        });
    }
    function applyMembers(members) {
        S.members = members || [];
        setMemberCount(S.members.length);
        renderMembers();
        var meIn = S.members.some(function (m) { return m.user_id === ME; });
        if (meIn !== S.isMember) { location.reload(); }
    }
    els.addBtn.addEventListener('click', function () {
        var uid = els.candidate.value;
        if (!uid) { showError(els.manageError, 'Select a user to add.'); return; }
        els.addBtn.disabled = true; showError(els.manageError, '');
        post({ action: 'add_member', group_id: S.groupId, user_id: uid }).then(function (res) {
            els.addBtn.disabled = false;
            if (!res.data.ok) { if (!handleAuthFailure(res)) showError(els.manageError, res.data.error); return; }
            applyMembers(res.data.members);
            loadCandidates();
        });
    });
    function confirmRemove(m) {
        var msg = 'Remove ' + m.name + ' from the group? They will immediately lose access to all messages and files.';
        var go = function () {
            post({ action: 'remove_member', group_id: S.groupId, user_id: m.user_id }).then(function (res) {
                if (!res.data.ok) { if (!handleAuthFailure(res)) showError(els.manageError, res.data.error); return; }
                applyMembers(res.data.members);
                loadCandidates();
            });
        };
        if (window.Swal) {
            Swal.fire({ title: 'Remove member?', text: msg, icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#b91c1c' })
                .then(function (r) { if (r.isConfirmed) go(); });
        } else if (window.confirm(msg)) { go(); }
    }
    var notesBtn = $('tcNotesBtn');
    window.addEventListener('load', function () {
        var fab = document.getElementById('fnotesFab');
        if (!fab) return;
        notesBtn.hidden = false;
        notesBtn.addEventListener('click', function () { fab.click(); });
    });

    els.membersBtn.addEventListener('click', openDialog);
    els.dialogClose.addEventListener('click', closeDialog);
    els.dialog.addEventListener('click', function (e) { if (e.target === els.dialog) closeDialog(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && els.dialog.classList.contains('show')) closeDialog(); });

    // ------------------------------------------------------------ groups
    function renderGroups() {
        var otherUnread = 0;
        document.querySelectorAll('[data-group-list]').forEach(function (box) {
            box.textContent = '';
            if (!S.groups.length) {
                box.appendChild(el('div', 'tc-groups-empty', S.canManage ? 'No groups yet — create one.' : 'You are not in any group yet.'));
                return;
            }
            S.groups.forEach(function (g) {
                var a = el('a', 'tc-group-item' + (g.id === S.groupId ? ' active' : '') + (g.is_member ? '' : ' not-member'));
                a.href = 'team_chat.php?group=' + g.id;
                a.setAttribute('data-testid', 'team-chat-group-item');
                a.setAttribute('data-group-id', String(g.id));
                if (g.id === S.groupId) a.setAttribute('aria-current', 'true');
                var ic = el('div', 'gi-icon', initials(g.name)); ic.setAttribute('aria-hidden', 'true');
                var meta = el('div', 'gi-meta');
                meta.appendChild(el('div', 'gi-name', g.name));
                meta.appendChild(el('div', 'gi-sub', g.is_member
                    ? g.member_count + ' member' + (g.member_count === 1 ? '' : 's')
                    : 'Not a member · ' + g.member_count + ' member' + (g.member_count === 1 ? '' : 's')));
                a.appendChild(ic); a.appendChild(meta);
                var unread = g.id === S.groupId ? 0 : (g.unread || 0);
                if (unread > 0) {
                    var b = el('span', 'gi-badge', unread > 99 ? '99+' : String(unread));
                    b.setAttribute('aria-label', unread + ' unread');
                    a.appendChild(b);
                }
                box.appendChild(a);
            });
        });
        S.groups.forEach(function (g) { if (g.id !== S.groupId) otherUnread += g.unread || 0; });
        var dot = $('tcGroupsDot');
        dot.textContent = otherUnread > 99 ? '99+' : String(otherUnread);
        dot.classList.toggle('show', otherUnread > 0);
    }
    function refreshGroups() {
        if (S.stopped || document.hidden) return;
        request('GET', { action: 'groups' }).then(function (res) {
            if (res.data.ok) { S.groups = res.data.groups || []; renderGroups(); }
        });
    }

    var openSheets = [];
    function openSheet(node) {
        openSheets.push({ node: node, focus: document.activeElement });
        node.classList.add('show'); node.setAttribute('aria-hidden', 'false');
        var f = node.querySelector('input[type=text], .btn-close'); if (f) f.focus();
    }
    function closeSheet(node) {
        node.classList.remove('show'); node.setAttribute('aria-hidden', 'true');
        for (var i = openSheets.length - 1; i >= 0; i--) {
            if (openSheets[i].node === node) { var f = openSheets[i].focus; openSheets.splice(i, 1); if (f && f.focus) f.focus(); break; }
        }
    }
    [$('tcGroupsDialog'), $('tcCreateDialog')].forEach(function (d) {
        d.addEventListener('click', function (e) { if (e.target === d || e.target.closest('[data-close-dialog]')) closeSheet(d); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openSheets.length) closeSheet(openSheets[openSheets.length - 1].node);
    });
    $('tcGroupsBtn').addEventListener('click', function () { openSheet($('tcGroupsDialog')); });

    var staff = [];
    function renderStaff() {
        var box = $('tcStaffList'), q = $('tcStaffSearch').value.trim().toLowerCase();
        var picked = {};
        box.querySelectorAll('input:checked').forEach(function (c) { picked[c.value] = true; });
        box.textContent = '';
        var shown = 0;
        staff.forEach(function (u) {
            var hide = q && (u.name + ' ' + u.role).toLowerCase().indexOf(q) === -1;
            var row = el('label', 'tc-staff-row');
            if (hide) row.hidden = true; else shown++;
            var cb = el('input'); cb.type = 'checkbox'; cb.className = 'form-check-input'; cb.value = String(u.user_id);
            cb.checked = !!(picked[cb.value] || u._picked);
            cb.addEventListener('change', function () { u._picked = cb.checked; updatePicked(); });
            var meta = el('div');
            meta.appendChild(el('div', 'tc-member-name', u.name));
            meta.appendChild(el('div', 'tc-member-role', u.role));
            row.appendChild(cb); row.appendChild(meta);
            box.appendChild(row);
        });
        if (!staff.length) box.appendChild(el('div', 'text-muted small py-2', 'No other staff accounts found.'));
        else if (!shown) box.appendChild(el('div', 'text-muted small py-2', 'No staff match your search.'));
        updatePicked();
    }
    function updatePicked() {
        var n = staff.filter(function (u) { return u._picked; }).length;
        $('tcPickedCount').textContent = n ? '(' + n + ' selected)' : '';
    }
    function openCreate() {
        if (!S.canManage) return;
        $('tcNewName').value = ''; $('tcStaffSearch').value = '';
        showError($('tcCreateError'), '');
        $('tcStaffList').textContent = '';
        $('tcStaffList').appendChild(el('div', 'text-muted small py-2', 'Loading staff…'));
        if (openSheets.length && openSheets[openSheets.length - 1].node.id === 'tcGroupsDialog') closeSheet($('tcGroupsDialog'));
        openSheet($('tcCreateDialog'));
        request('GET', { action: 'staff' }).then(function (res) {
            if (!res.data.ok) { showError($('tcCreateError'), res.data.error || 'Staff list could not be loaded.'); $('tcStaffList').textContent = ''; return; }
            staff = res.data.staff || [];
            renderStaff();
        });
    }
    document.querySelectorAll('[data-new-group]').forEach(function (b) { b.addEventListener('click', openCreate); });
    $('tcStaffSearch').addEventListener('input', renderStaff);
    $('tcCreateForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var name = $('tcNewName').value.trim();
        if (name.length < 2) { showError($('tcCreateError'), 'Enter a group name (at least 2 characters).'); $('tcNewName').focus(); return; }
        var fd = new FormData();
        fd.append('action', 'create_group'); fd.append('name', name);
        staff.forEach(function (u) { if (u._picked) fd.append('member_ids[]', String(u.user_id)); });
        var btn = $('tcCreateBtn'); btn.disabled = true; btn.textContent = 'Creating…';
        showError($('tcCreateError'), '');
        post(fd).then(function (res) {
            if (!res.data.ok) {
                btn.disabled = false; btn.textContent = 'Create Group';
                if (!handleAuthFailure(res)) showError($('tcCreateError'), res.data.error || 'Group could not be created.');
                return;
            }
            location.href = 'team_chat.php?group=' + res.data.group.id;
        });
    });

    boot();
})();
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
