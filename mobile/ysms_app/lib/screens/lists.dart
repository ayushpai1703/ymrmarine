// Vessel lists (app_api.php?a=list: the same vessels, order and search as the website lists) and search.
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api.dart';
import '../ui.dart';
import 'vessel.dart';

enum ListKind {
  vessels('pending_vessel', 'Pending Vessels', 'No pending vessels', 'Overdue', 'export_vessels.php'),
  reports('pending_report', 'Pending Reports', 'No pending reports', 'Report overdue', 'export_reports.php'),
  completed('completed', 'Completed', 'No completed vessels', null, 'export_completed.php'),
  cancelled('cancelled', 'Cancelled', 'No cancelled vessels', null, 'export_cancelled.php');

  const ListKind(this.status, this.title, this.empty, this.overdue, this.export);
  final String status, title, empty;
  final String? overdue;
  final String export;
}

void openVessel(BuildContext context, Map<String, dynamic> v) {
  Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => VesselScreen(id: (v['id'] as num).toInt(), first: v),
    ),
  );
}

class ListScreen extends StatefulWidget {
  const ListScreen({super.key, required this.kind, this.pushed = false});
  final ListKind kind;
  final bool pushed;
  @override
  State<ListScreen> createState() => _ListScreenState();
}

class _ListScreenState extends State<ListScreen> {
  final _items = <Map<String, dynamic>>[];
  final _scroll = ScrollController();
  final _q = TextEditingController();
  Timer? _t;
  int _page = 1, _total = -1, _seq = 0;
  bool _more = false, _loading = false, _overdue = false;
  Object? _error;

  bool get _showOverdue {
    final role = s(Api.i.me?['role']);
    return widget.kind.overdue != null && const ['Admin', 'Super Admin', 'Surveyor'].contains(role);
  }

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_more && !_loading && _scroll.position.pixels > _scroll.position.maxScrollExtent - 400) _load(false);
    });
    _load(true);
  }

  @override
  void dispose() {
    _t?.cancel();
    _scroll.dispose();
    _q.dispose();
    super.dispose();
  }

  Future<void> _load(bool reset) async {
    final my = ++_seq;
    if (reset) {
      _page = 1;
      _more = false;
    } else {
      _page++;
    }
    setState(() {
      _loading = true;
      if (reset) _error = null;
    });
    try {
      final d = await Api.i.get('list', {
        'status': widget.kind.status,
        'page': _page,
        if (_q.text.trim().isNotEmpty) 'q': _q.text.trim(),
        if (_overdue) 'overdue': 1,
      });
      if (my != _seq || !mounted) return;
      final items = (d['items'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      Pics.take(items);
      setState(() {
        if (reset) _items.clear();
        _items.addAll(items);
        _total = (d['total'] as num).toInt();
        _more = d['more'] == true;
        _loading = false;
      });
    } catch (e) {
      if (my != _seq || !mounted) return;
      setState(() {
        _loading = false;
        if (reset) _error = e;
        if (!reset) _page--;
      });
      if (!reset && e is ApiError && !e.login) toast(context, 'Could not load more', bad: true);
    }
  }

  void _search(String _) {
    _t?.cancel();
    _t = Timer(const Duration(milliseconds: 320), () => _load(true));
  }

  @override
  Widget build(BuildContext context) {
    final me = Api.i.me ?? {};
    final can = (me['can'] as Map?) ?? {};
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: widget.pushed,
        titleSpacing: widget.pushed ? 0 : 20,
        toolbarHeight: 64,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.kind.title),
            Text(
              _total < 0 ? ' ' : '$_total ${_total == 1 ? 'vessel' : 'vessels'}',
              style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500, color: mutedText(context)),
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Download Excel',
            icon: const Icon(Icons.download_rounded),
            onPressed: () => downloadAndOpen(context, widget.kind.export),
          ),
          const SizedBox(width: 6),
        ],
      ),
      floatingActionButton: widget.kind == ListKind.vessels && can['assign'] == true
          ? FloatingActionButton.extended(
              onPressed: () => openAppRoute(context, '/assign'),
              icon: const Icon(Icons.add_rounded),
              label: Text(s(me['role']) == 'Client' ? 'Request survey' : 'Assign vessel'),
            )
          : null,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
            child: TextField(
              controller: _q,
              onChanged: _search,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Search vessel, port, client',
                prefixIcon: const Icon(Icons.search_rounded),
                suffixIcon: _q.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () {
                          _q.clear();
                          _load(true);
                        },
                      ),
                contentPadding: const EdgeInsets.symmetric(vertical: 12),
              ),
            ),
          ),
          if (_showOverdue)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
              child: Row(
                children: [
                  ChoiceChip(
                    label: const Text('All'),
                    selected: !_overdue,
                    onSelected: (_) {
                      HapticFeedback.selectionClick();
                      setState(() => _overdue = false);
                      _load(true);
                    },
                  ),
                  const SizedBox(width: 8),
                  ChoiceChip(
                    label: Text(widget.kind.overdue!),
                    selected: _overdue,
                    selectedColor: orange.withValues(alpha: .2),
                    onSelected: (_) {
                      HapticFeedback.selectionClick();
                      setState(() => _overdue = true);
                      _load(true);
                    },
                  ),
                ],
              ),
            ),
          Expanded(
            child: RefreshIndicator(onRefresh: () => _load(true), child: _body()),
          ),
        ],
      ),
    );
  }

  Widget _body() {
    if (_error != null && _items.isEmpty) {
      return ListView(
        children: [ErrorState(error: _error!, onRetry: () => _load(true))],
      );
    }
    if (_items.isEmpty && _loading) {
      return ListView(padding: const EdgeInsets.all(16), children: const [CardSkeletons()]);
    }
    if (_items.isEmpty) {
      return ListView(
        children: [
          EmptyState(
            icon: widget.kind == ListKind.completed ? Icons.task_alt_rounded : Icons.directions_boat_outlined,
            text: _q.text.isNotEmpty || _overdue ? 'Nothing matches' : widget.kind.empty,
          ),
        ],
      );
    }
    return ListView.builder(
      controller: _scroll,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 96),
      itemCount: _items.length + (_more ? 1 : 0),
      itemBuilder: (_, i) {
        if (i >= _items.length) {
          return const Padding(
            padding: EdgeInsets.all(18),
            child: Center(child: CircularProgressIndicator(strokeWidth: 2.4)),
          );
        }
        final v = _items[i];
        return VesselCard(v: v, onTap: () => openVessel(context, v));
      },
    );
  }
}

/// Search pending, report and completed vessels at once.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});
  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _q = TextEditingController();
  Timer? _t;
  int _seq = 0;
  bool _loading = false;
  List<(String, int, List<Map<String, dynamic>>)>? _res;

  @override
  void dispose() {
    _t?.cancel();
    _q.dispose();
    super.dispose();
  }

  void _changed(String v) {
    _t?.cancel();
    if (v.trim().length < 2) return;
    _t = Timer(const Duration(milliseconds: 320), _run);
  }

  Future<void> _run() async {
    final q = _q.text.trim();
    final my = ++_seq;
    setState(() => _loading = true);
    try {
      const kinds = [ListKind.vessels, ListKind.reports, ListKind.completed];
      final r = await Future.wait(kinds.map((k) => Api.i.get('list', {'status': k.status, 'q': q})));
      if (my != _seq || !mounted) return;
      final out = <(String, int, List<Map<String, dynamic>>)>[];
      for (var n = 0; n < kinds.length; n++) {
        final items = (r[n]['items'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        Pics.take(items);
        if (items.isNotEmpty) out.add((kinds[n].title, (r[n]['total'] as num).toInt(), items.take(10).toList()));
      }
      setState(() {
        _res = out;
        _loading = false;
      });
    } catch (e) {
      if (my != _seq || !mounted) return;
      setState(() => _loading = false);
      if (e is ApiError && !e.login) toast(context, e.offline ? e.message : 'Could not search', bad: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final res = _res;
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Padding(
          padding: const EdgeInsets.only(right: 16),
          child: TextField(
            controller: _q,
            autofocus: true,
            onChanged: _changed,
            onSubmitted: (_) => _run(),
            textInputAction: TextInputAction.search,
            decoration: const InputDecoration(
              hintText: 'Vessel, port, client, agent',
              prefixIcon: Icon(Icons.search_rounded),
              contentPadding: EdgeInsets.symmetric(vertical: 12),
            ),
          ),
        ),
      ),
      body: _loading && res == null
          ? ListView(padding: const EdgeInsets.all(16), children: const [CardSkeletons(count: 3)])
          : res == null
          ? const EmptyState(icon: Icons.search_rounded, text: 'Search pending, report and completed vessels')
          : res.isEmpty
          ? EmptyState(icon: Icons.search_off_rounded, text: 'Nothing matches “${_q.text.trim()}”')
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
              children: [
                if (_loading) const LinearProgressIndicator(minHeight: 2),
                for (final g in res) ...[
                  SectionTitle(
                    g.$1,
                    trailing: Text(
                      '${g.$2}',
                      style: TextStyle(color: mutedText(context), fontWeight: FontWeight.w700),
                    ),
                  ),
                  for (final v in g.$3) VesselCard(v: v, onTap: () => openVessel(context, v)),
                ],
              ],
            ),
    );
  }
}
