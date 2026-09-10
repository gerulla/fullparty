import assert from 'node:assert/strict';
import { test } from 'node:test';
import { specialistDesignationActions, specialistDesignationMarkers } from '../../resources/js/utils/specialistDesignations.ts';

const slot = {id:1, assigned_character_id:2, is_host:false, is_raid_leader:false, is_duelist:false, is_trapper:false, is_darter:false, is_bench:false, is_fill_in:false};
const t = key => key;

test('shows only the specialist actions allowed by the server', () => {
    assert.equal(specialistDesignationActions(slot,t,()=>{},false).length,0);
    assert.deepEqual(specialistDesignationActions({...slot,available_designations:['host','raid_leader','trapper']},t,()=>{},false).map(a=>a.icon),['i-lucide-circle-gauge']);
    assert.deepEqual(specialistDesignationActions({...slot,available_designations:['duelist','trapper']},t,()=>{},false).map(a=>a.icon),['i-lucide-sword','i-lucide-circle-gauge']);
    assert.deepEqual(specialistDesignationActions({...slot,available_designations:['host','raid_leader','trapper','darter']},t,()=>{},false).map(a=>a.icon),['i-lucide-circle-gauge','gravity-ui:target-dart']);
});

test('uses red duelists and orange trappers with distinct marker positions', () => {
    const markers = specialistDesignationMarkers({...slot,is_host:true,is_duelist:true,is_trapper:true});
    assert.match(markers[0].iconClass,/text-red-500/);
    assert.match(markers[1].iconClass,/text-orange-500/);
    for (const marker of markers) assert.match(marker.iconClass,/\bscale-77\b/);
    assert.equal(markers[0].wrapperClass,'-right-2 -top-2');
    assert.equal(markers[1].wrapperClass,'right-5 -top-2');
    assert.equal(specialistDesignationMarkers({...slot,is_trapper:true})[0].wrapperClass,'-right-2 -top-2');
});

test('reserves the right corner for self and application review markers', () => {
    const marked = {...slot,is_duelist:true,is_trapper:true};
    assert.deepEqual(specialistDesignationMarkers(marked,true).map(marker=>marker.wrapperClass),['right-5 -top-2','right-12 -top-2']);
    assert.equal(specialistDesignationMarkers({...slot,is_trapper:true},true)[0].wrapperClass,'right-5 -top-2');
    assert.deepEqual(specialistDesignationMarkers({...marked,is_raid_leader:true}),specialistDesignationMarkers(marked));
});

test('toggles existing marks through the designation event and disables invalid targets', () => {
    let selection;
    const [action] = specialistDesignationActions({...slot,is_duelist:true,available_designations:['duelist']},t,(...args)=>selection=args,false);
    assert.match(action.label,/unmark_duelist_action$/);
    action.onSelect();
    assert.deepEqual(selection,[1,'duelist']);
    for(const patch of [{is_bench:true},{is_fill_in:true},{assigned_character_id:null}]) {
        assert.equal(specialistDesignationActions({...slot,...patch,available_designations:['duelist']},t,()=>{},false)[0].disabled,true);
    }
    assert.equal(specialistDesignationActions({...slot,available_designations:['duelist']},t,()=>{},true)[0].disabled,true);
});

test('uses a medium-dark green darter icon beside trapper and reserves the self marker corner', () => {
    const marked = {...slot,is_trapper:true,is_darter:true};
    const markers = specialistDesignationMarkers(marked,true);
    assert.deepEqual(markers.map(marker=>marker.wrapperClass),['right-5 -top-2','right-12 -top-2']);
    const darter = markers[1];
    assert.equal(darter.icon,'gravity-ui:target-dart');
    assert.match(darter.iconClass,/text-green-600/);
    assert.match(darter.iconClass,/\bscale-77\b/);
    assert.equal(darter.badgeClass,'bg-green-600/10 text-green-600');
    assert.match(darter.labelKey,/darter_badge$/);
});

test('marks and unmarks darters using the existing selection handler', () => {
    let selection;
    for (const is_darter of [false,true]) {
        const [action] = specialistDesignationActions({...slot,is_darter,available_designations:['darter']},t,(...args)=>selection=args,false);
        assert.match(action.label,is_darter ? /\.unmark_darter_action$/ : /\.mark_darter_action$/);
        assert.equal(action.color,'success');
        assert.deepEqual(action.ui,{itemLabel:'text-green-600',itemLeadingIcon:'text-green-600'});
        action.onSelect();
        assert.deepEqual(selection,[1,'darter']);
    }
    for (const patch of [{is_bench:true},{is_fill_in:true},{assigned_character_id:null}]) {
        assert.equal(specialistDesignationActions({...slot,...patch,available_designations:['darter']},t,()=>{},false)[0].disabled,true);
    }
    assert.equal(specialistDesignationActions({...slot,available_designations:['darter']},t,()=>{},true)[0].disabled,true);
});
