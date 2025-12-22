# Google Calendar → FPP YAML Reference

This plugin allows **Google Calendar events** to control the **Falcon Player (FPP) scheduler**.

Most events require **no YAML at all**.  
YAML is optional and used only to override behavior when needed.

---

## 🧠 How It Works (Simple Mental Model)

- **Calendar controls WHEN**
  - Date
  - Time
  - Repeats
  - Overlaps / priority

- **YAML controls HOW**
  - Playlist vs sequence vs command
  - Stop behavior
  - Repeat behavior
  - Command arguments

- **FPP executes WHAT**
  - Playlists
  - Sequences
  - Commands

---

## ✅ Basic Playlist Event (No YAML)

If a playlist exists in FPP:

**Calendar Event Title**
```
My_Playlist
```

That’s it.

---

## 🧾 Common YAML Options

```yaml
enabled: true
stoptype: graceful
repeat: none
```

| Key | Description |
|----|------------|
| `enabled` | Enable or disable the event |
| `stoptype` | `graceful`, `graceful_loop`, or `hard` |
| `repeat` | `none`, `immediate`, or a number |

---

## 🎵 Force Playlist or Sequence Type

```yaml
type: playlist
```

or

```yaml
type: sequence
```

---

## ⚡ Command Events

### Simple Command
```yaml
type: command
command: All Lights Off
```

### Command With Arguments
```yaml
type: command
command: Volume Set
args:
  - 70
```

### Trigger a Command Preset
```yaml
type: command
command: Trigger Command Preset
args:
  - Xmas_Start
```

### Multisync Command
```yaml
type: command
command: All Lights Off
multisynccommand: true
```

### Disable an Event
```yaml
enabled: false
```

---

## 🚫 What YAML Cannot Control

- Date / time
- Day or daymask
- Start / end date
- Priority

---

## 🎄 Final Thought

If you can schedule it on a calendar, you can control it in FPP.
