# 私信历史消息结构化迁移说明

## 迁移内容

- 将 `private_messages.content` 中的旧数据整理为结构化格式
- 回填 `private_messages.content_text`
- 回填 `private_messages.content_images`
- 保留 `content` 兼容字段，避免旧代码读取异常

## 迁移页面

后台新增迁移工具：

- `admin/migrate_private_messages.php`

使用方式：

1. 使用超级管理员账号登录后台
2. 打开迁移页面
3. 先点击“预览迁移结果”确认影响范围
4. 再点击“开始执行迁移”完成批量回填

## 数据结构约定

- `content_text`：纯文本内容
- `content_images`：图片列表，JSON 数组或 `NULL`
- `content`：兼容字段，统一保存为 JSON 结构

示例：

```json
{
  "text": "这是一条消息",
  "images": [
    "/uploads/private_messages/20260527_xxx.png"
  ]
}
```

## 兼容说明

- 如果历史消息 `content` 是纯文本，迁移后 `content_text` 保留原内容，`content_images` 为空
- 如果历史消息 `content` 已经是 JSON 结构，会自动拆分出文本和图片
- 新消息发送逻辑已经兼容该结构，无需额外改动客户端
