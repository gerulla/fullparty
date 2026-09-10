export async function readWorkspaceImage(file: File): Promise<string> {
    if (!['image/png', 'image/jpeg', 'image/webp', 'image/gif'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        throw new Error('invalid_image')
    }
    const data = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result))
        reader.onerror = reject
        reader.readAsDataURL(file)
    })
    const image = new Image()
    image.src = data
    await image.decode()
    if (image.naturalWidth > 4096 || image.naturalHeight > 4096) throw new Error('invalid_dimensions')
    return data
}
