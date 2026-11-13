// Home: the website dashboard for this role (app_api.php?a=dashboard, insights, month).
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api.dart';
import '../bio.dart';
import '../main.dart' show Shell;
import '../ui.dart';
import 'actions.dart';
import 'lists.dart';
import 'vessel.dart';

const _months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const _qaMax = 4;

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  Map<String, dynamic>? _d, _ins, _month;
  Object? _error;
  List<String>? _quick;

  Map<String, dynamic> get me => Api.i.me ?? {};

  @override
  void initState() {
    super.initState();
    _loadQuick();
    _load();
  }

  Future<void> _loadQuick() async {
    final raw = await Prefs.read('quick:${me['id']}');
    if (raw == null) return;
    try {
      final l = (jsonDecode(raw) as List).map((e) => '$e').toList();
      if (mounted) setState(() => _quick = l);
    } catch (_) {}
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = await Future.wait([Api.i.get('dashboard'), Api.i.loadMe()]);
      if (!mounted) return;
      setState(() {
        _d = r[0];
        _month = null;
      });
    } catch (e) {
      if (mounted && _d == null) setState(() => _error = e);
      if (mounted && _d != null && e is ApiError && !e.login) toast(context, 'Could not refresh', bad: true);
    }
    try {
      final i = await Api.i.get('insights');
      if (mounted) setState(() => _ins = i);
    } catch (_) {}
  }

  List<AppAction> get _chosen {
    final all = allActions(me);
    final pick = (_quick ?? []).map((k) => all.where((a) => a.key == k).firstOrNull).whereType<AppAction>().toList();
    return (pick.isNotEmpty ? pick : all).take(_qaMax).toList();
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        body: RefreshIndicator(
          onRefresh: _load,
          edgeOffset: 120,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              _header(context),
              Transform.translate(
                offset: const Offset(0, -26),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _searchBar(context),
                      const SizedBox(height: 16),
                      if (_error != null && d == null)
                        ErrorState(error: _error!, onRetry: _load)
                      else ...[
                        _stats(context, d),
                        if (d != null && (d['attention'] as List).isNotEmpty) _attention(context, d['attention'] as List),
                        SectionTitle(
                          'Quick actions',
                          trailing: TextButton.icon(
                            onPressed: _editQuick,
                            icon: const Icon(Icons.edit_outlined, size: 18),
                            label: const Text('Edit'),
                          ),
                        ),
                        _quickRow(context),
                        const SizedBox(height: 18),
                        if (d == null) const Skeleton(height: 150, radius: 22) else _monthCard(context, d),
                        if (_ins != null) ..._insights(context, _ins!),
                        const SizedBox(height: 24),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    final top = MediaQuery.of(context).padding.top;
    final unread = (me['unread'] as num?)?.toInt() ?? 0;
    final can = (me['can'] as Map?) ?? {};
    return Container(
      padding: EdgeInsets.fromLTRB(20, top + 14, 12, 50),
      decoration: const BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [navy2, navy]),
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Row(
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(30),
            onTap: () => Shell.tab.value = 4,
            child: Avatar(me: me, size: 48, light: true),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  s(me['greeting']),
                  style: const TextStyle(color: Color(0xFF9FC3FF), fontSize: 13, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 2),
                Text(
                  s(me['name']),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800, letterSpacing: -.3),
                ),
              ],
            ),
          ),
          if (can['inbox'] == true)
            IconButton(
              tooltip: unread > 0 ? 'Inbox, $unread unread' : 'Inbox',
              onPressed: () async {
                await openAppRoute(context, '/inbox');
                _load();
              },
              icon: Badge(
                isLabelVisible: unread > 0,
                label: Text(unread > 99 ? '99+' : '$unread'),
                child: const Icon(Icons.notifications_none_rounded, color: Colors.white, size: 27),
              ),
            ),
        ],
      ),
    );
  }

  Widget _searchBar(BuildContext context) => Material(
    color: cardBg(context),
    elevation: isDark(context) ? 0 : 6,
    shadowColor: const Color(0x330B1E46),
    borderRadius: BorderRadius.circular(16),
    child: InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SearchScreen())),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
        child: Row(
          children: [
            Icon(Icons.search_rounded, color: mutedText(context)),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                'Search vessel, port, client…',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(color: mutedText(context), fontSize: 15),
              ),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _stats(BuildContext context, Map<String, dynamic>? d) {
    final c = d?['counts'] as Map?;
    Widget tile(IconData ic, Color col, String? n, String label, VoidCallback? tap) => Box(
      onTap: tap,
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          IconBadge(ic, col),
          n == null
              ? const Skeleton(height: 26, width: 60)
              : FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(n, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: -.5)),
                ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: 12.5, color: mutedText(context), fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
    final can = (me['can'] as Map?) ?? {};
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 1.25,
      padding: EdgeInsets.zero,
      children: [
        tile(
          Icons.directions_boat_rounded,
          blue,
          c == null ? null : '${c['pending_vessel']}',
          'Pending Vessels',
          () => Shell.tab.value = 1,
        ),
        tile(Icons.description_rounded, orange, c == null ? null : '${c['pending_report']}', 'Pending Reports', () => Shell.tab.value = 2),
        tile(Icons.task_alt_rounded, green, c == null ? null : '${c['completed']}', 'Completed', () => Shell.tab.value = 3),
        tile(
          Icons.insights_rounded,
          sky,
          d == null ? null : fmtMt(d['recovery_month']),
          'Recovery · month (MT)',
          can['recovery'] == true ? () => openAppRoute(context, '/recovery') : null,
        ),
      ],
    );
  }

  Widget _attention(BuildContext context, List att) => Padding(
    padding: const EdgeInsets.only(top: 16),
    child: Box(
      padding: const EdgeInsets.fromLTRB(16, 14, 8, 6),
      color: isDark(context) ? const Color(0xFF2A1D14) : const Color(0xFFFFF8F0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.schedule_rounded, color: orange, size: 20),
              SizedBox(width: 8),
              Text('Needs attention', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            ],
          ),
          const SizedBox(height: 6),
          for (final a in att.cast<Map>())
            ListTile(
              contentPadding: const EdgeInsets.only(left: 0, right: 4),
              dense: true,
              title: Text(s(a['vessel']), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
              subtitle: Text(
                a['kind'] == 'survey'
                    ? 'Survey pending · ${a['days']} days since appointment'
                    : 'Report overdue · ${a['days']} days since survey',
              ),
              leading: Container(width: 4, height: 34, color: a['kind'] == 'survey' ? orange : red),
              minLeadingWidth: 4,
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => VesselScreen(id: (a['id'] as num).toInt()))),
            ),
        ],
      ),
    ),
  );

  Widget _quickRow(BuildContext context) {
    final list = _chosen;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < _qaMax; i++)
          Expanded(
            child: i >= list.length
                ? const SizedBox()
                : InkWell(
                    borderRadius: BorderRadius.circular(16),
                    onTap: () async {
                      HapticFeedback.lightImpact();
                      await runAction(context, list[i]);
                      if (mounted) setState(() {});
                    },
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Column(
                        children: [
                          Container(
                            width: 56,
                            height: 56,
                            decoration: BoxDecoration(
                              color: i == 0 ? navy : cardBg(context),
                              borderRadius: BorderRadius.circular(18),
                              border: i == 0 ? null : Border.all(color: lineColor(context)),
                            ),
                            child: Icon(list[i].icon, color: i == 0 ? Colors.white : list[i].color),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            list[i].label,
                            textAlign: TextAlign.center,
                            maxLines: 2,
                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, height: 1.2),
                          ),
                        ],
                      ),
                    ),
                  ),
          ),
      ],
    );
  }

  Future<void> _editQuick() async {
    final all = allActions(me);
    final sel = _chosen.map((a) => a.key).toList();
    final res = await showModalBottomSheet<List<String>>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.of(ctx).size.height * .8),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Padding(
                  padding: EdgeInsets.fromLTRB(20, 0, 20, 4),
                  child: Text('Quick actions', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                  child: Text('Choose up to $_qaMax. They show in the order you tap them.', style: TextStyle(color: mutedText(ctx))),
                ),
                Flexible(
                  child: ListView(
                    shrinkWrap: true,
                    children: [
                      for (final a in all)
                        CheckboxListTile(
                          value: sel.contains(a.key),
                          secondary: IconBadge(a.icon, a.color, size: 36),
                          title: Text(a.label),
                          onChanged: (on) => set(() {
                            if (on == true && !sel.contains(a.key) && sel.length < _qaMax) sel.add(a.key);
                            if (on == false) sel.remove(a.key);
                          }),
                        ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    onPressed: sel.isEmpty ? null : () => Navigator.pop(ctx, sel),
                    child: const Text('Save'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    if (res == null) return;
    await Prefs.write('quick:${me['id']}', jsonEncode(res));
    setState(() => _quick = res);
    if (mounted) toast(context, 'Quick actions updated', ok: true);
  }

  Widget _monthCard(BuildContext context, Map<String, dynamic> d) {
    final now = DateTime.now();
    final m = _month ?? {'y': now.year, 'm': now.month, 'label': d['month'], 'ships': d['ships_month'], 'recovery': d['recovery_month']};
    Widget fig(String n, String l) => Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              n,
              style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800),
            ),
          ),
          const SizedBox(height: 2),
          Text(l, style: const TextStyle(color: Color(0xFFB9CCF2), fontSize: 12)),
        ],
      ),
    );
    return Container(
      padding: const EdgeInsets.fromLTRB(18, 10, 10, 18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF1D4FB8), navy], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Flexible(
                flex: 3,
                child: TextButton.icon(
                  style: TextButton.styleFrom(foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 4)),
                  onPressed: () => _pickMonth(m),
                  icon: const Icon(Icons.calendar_month_rounded, size: 20),
                  label: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Flexible(
                        child: Text(
                          s(m['label']),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                        ),
                      ),
                      const Icon(Icons.expand_more_rounded),
                    ],
                  ),
                ),
              ),
              const Spacer(),
              TextButton.icon(
                style: TextButton.styleFrom(foregroundColor: Colors.white),
                onPressed: () => downloadAndOpen(context, 'export_month_summary.php?y=${m['y']}&m=${m['m']}'),
                icon: const Icon(Icons.download_rounded, size: 19),
                label: const Text('Excel'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              fig('${m['ships']}', 'Ships surveyed'),
              fig('${fmtMt(m['recovery'])} MT', 'Recovery'),
              fig('${fmtMt(d['recovery_total'])} MT', 'Total recovery'),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _pickMonth(Map<String, dynamic> cur) async {
    Map<String, dynamic> r;
    try {
      r = await Api.i.get('month', {'y': cur['y'], 'm': cur['m']});
    } catch (_) {
      if (mounted) toast(context, 'Could not load months', bad: true);
      return;
    }
    if (!mounted) return;
    final years = ((r['years'] as List?) ?? []).map((e) => (e as num).toInt()).toList();
    var y = (cur['y'] as num).toInt();
    if (!years.contains(y)) years.insert(0, y);
    final pick = await showModalBottomSheet<(int, int)>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) {
          final now = DateTime.now();
          final maxM = y == now.year ? now.month : 12;
          return SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Month summary', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [for (final x in years) ChoiceChip(label: Text('$x'), selected: x == y, onSelected: (_) => set(() => y = x))],
                  ),
                  const SizedBox(height: 16),
                  GridView.count(
                    crossAxisCount: 4,
                    shrinkWrap: true,
                    childAspectRatio: 2,
                    mainAxisSpacing: 8,
                    crossAxisSpacing: 8,
                    children: [
                      for (var i = 1; i <= 12; i++)
                        OutlinedButton(
                          onPressed: i > maxM ? null : () => Navigator.pop(ctx, (y, i)),
                          style: OutlinedButton.styleFrom(
                            padding: EdgeInsets.zero,
                            backgroundColor: y == cur['y'] && i == cur['m'] ? blueSoft : null,
                          ),
                          child: Text(_months[i - 1].substring(0, 3)),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
    if (pick == null) return;
    try {
      final n = await Api.i.get('month', {'y': pick.$1, 'm': pick.$2});
      if (mounted) setState(() => _month = n);
    } catch (_) {
      if (mounted) toast(context, 'Could not load that month', bad: true);
    }
  }

  List<Widget> _insights(BuildContext context, Map<String, dynamic> d) {
    final out = <Widget>[];
    final recent = d['recent'] as Map?;
    if (recent != null) {
      out.add(const SectionTitle('Recent survey recovery'));
      out.add(
        Box(
          onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => VesselScreen(id: (recent['id'] as num).toInt()))),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(s(recent['vessel']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                  ),
                  const Icon(Icons.chevron_right_rounded),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  for (final k in ['vlsfo', 'lsmgo', 'hsfo'])
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            k.toUpperCase(),
                            style: TextStyle(fontSize: 11.5, color: mutedText(context), fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 2),
                          Text(s(recent[k]), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                        ],
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      );
    }
    Widget mini(IconData ic, Color c, String n, String l) => Box(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          IconBadge(ic, c, size: 34),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(n, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
          ),
          Text(
            l,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: 12, color: mutedText(context)),
          ),
        ],
      ),
    );
    final sa = d['sa'] as Map?;
    final tiles = <Widget>[
      mini(Icons.directions_boat_rounded, blue, ((d['avg_ships_month'] as num?) ?? 0).toStringAsFixed(1), 'Ships per month'),
      if (d['avg_rec_ship'] != null) mini(Icons.local_gas_station_rounded, sky, '${fmtMt(d['avg_rec_ship'])} MT', 'Recovery per ship'),
      if (d['avg_rec_surveyor'] != null) mini(Icons.person_rounded, purple, '${fmtMt(d['avg_rec_surveyor'])} MT', 'Recovery per surveyor'),
      if (sa != null) ...[
        mini(Icons.calendar_today_rounded, blue, ((sa['avg_year'] as num?) ?? 0).toStringAsFixed(1), 'Ships per year'),
        mini(Icons.schedule_rounded, orange, ((sa['avg_day'] as num?) ?? 0).toStringAsFixed(1), 'Ships per day'),
        mini(Icons.groups_rounded, green, ((sa['avg_surveyor'] as num?) ?? 0).toStringAsFixed(1), 'Ships per surveyor'),
      ],
    ];
    out.add(const SectionTitle('Averages'));
    out.add(
      GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 12,
        crossAxisSpacing: 12,
        childAspectRatio: 1.6,
        padding: EdgeInsets.zero,
        children: tiles,
      ),
    );
    final sv = d['surveyors'] as List?;
    if (sv != null) {
      final t = (d['surveyors_total'] as Map?) ?? {};
      final can = (me['can'] as Map?) ?? {};
      out.add(
        SectionTitle(
          'Surveyor report',
          trailing: can['recovery'] == true
              ? TextButton(onPressed: () => openAppRoute(context, '/recovery'), child: const Text('Recovery details'))
              : null,
        ),
      );
      TextStyle h = TextStyle(fontSize: 11.5, color: mutedText(context), fontWeight: FontWeight.w700);
      Widget row(String name, String sub, String ships, String rec, String recM, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 9),
        child: Row(
          children: [
            Expanded(
              flex: 5,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w700),
                  ),
                  if (sub.isNotEmpty) Text(sub, style: TextStyle(fontSize: 11.5, color: mutedText(context))),
                ],
              ),
            ),
            Expanded(
              flex: 3,
              child: Text(
                ships,
                textAlign: TextAlign.center,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            Expanded(
              flex: 4,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(rec, style: const TextStyle(fontWeight: FontWeight.w700)),
                  Text(recM, style: TextStyle(fontSize: 11.5, color: mutedText(context))),
                ],
              ),
            ),
          ],
        ),
      );
      out.add(
        Box(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Column(
            children: [
              Row(
                children: [
                  Expanded(flex: 5, child: Text('Surveyor', style: h)),
                  Expanded(
                    flex: 3,
                    child: Text('Ships\ntotal · month', textAlign: TextAlign.center, style: h),
                  ),
                  Expanded(
                    flex: 4,
                    child: Text('Recovery MT\ntotal · month', textAlign: TextAlign.end, style: h),
                  ),
                ],
              ),
              const Divider(),
              for (final r in sv.cast<Map>())
                row(
                  s(r['name']),
                  r['inactive'] == true ? 'Inactive' : '',
                  '${r['ships_total']} · ${r['ships_month']}',
                  fmtMt(r['recovery_total']),
                  fmtMt(r['recovery_month']),
                ),
              const Divider(),
              row(
                'Total',
                'each vessel once',
                '${t['ships_total'] ?? 0} · ${t['ships_month'] ?? 0}',
                fmtMt(t['recovery_total']),
                fmtMt(t['recovery_month']),
                bold: true,
              ),
            ],
          ),
        ),
      );
    }
    if (sa != null) {
      out.add(const SectionTitle('Super Admin analytics'));
      for (final e in [
        ('Recovery by surveyor · top 10', 'rec_surveyor', true),
        ('Recovery by port · top 5', 'rec_port', true),
        ('Recovery by country · top 5', 'rec_country', true),
        ('Recovery by client · top 5', 'rec_client', true),
        ('Completed ships by country · top 5', 'ships_country', false),
        ('Completed ships by port · top 5', 'ships_port', false),
      ]) {
        out.add(_topList(context, e.$1, ((sa[e.$2] as List?) ?? []).cast<Map>(), e.$3));
      }
    }
    return out;
  }

  Widget _topList(BuildContext context, String title, List<Map> rows, bool mt) {
    final max = rows.fold<double>(0, (a, r) => ((r['value'] as num).toDouble() > a) ? (r['value'] as num).toDouble() : a);
    return Box(
      margin: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 10),
          if (rows.isEmpty) Text('No data yet', style: TextStyle(color: mutedText(context))),
          for (var i = 0; i < rows.length; i++)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(
                children: [
                  SizedBox(
                    width: 22,
                    child: Text(
                      '${i + 1}',
                      style: TextStyle(color: mutedText(context), fontWeight: FontWeight.w700),
                    ),
                  ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          s(rows[i]['name']),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        const SizedBox(height: 4),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: LinearProgressIndicator(
                            value: max == 0 ? 0 : ((rows[i]['value'] as num).toDouble() / max).clamp(.04, 1.0),
                            minHeight: 6,
                            backgroundColor: lineColor(context),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Text(mt ? '${fmtMt(rows[i]['value'])} MT' : '${rows[i]['value']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
